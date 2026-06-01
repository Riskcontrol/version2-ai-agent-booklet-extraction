#!/usr/bin/env python3
"""
peldarg_finalize.py — Called by GitHub Actions after extraction is complete.

Sends a HMAC-signed finalize-extraction request directly to Peldarg,
independent of the RCS application server and its .env configuration.

This script is the billing-security anchor: even if RCS is misconfigured
(wrong token, shadow mode, etc.), credit deduction still happens because
GitHub Actions has its own copy of the Peldarg secrets.

Required environment variables (from GitHub Actions secrets):
  PELDARG_BASE_URL               e.g. https://extraction.peldargconsulting.com
  PELDARG_PARTNER_TOKEN          shared token (X-Partner-Token header)
  PELDARG_PARTNER_NAME           e.g. riskcontrol
  PELDARG_PARTNER_SIGNATURE_SECRET  HMAC secret for request signing

Required env vars (from client_payload / workflow context):
  PARTNER_REQUEST_ID             UUID returned by Peldarg during authorize step
  PAGES_PROCESSED                int
  PAGES_WITH_RESULTS             int
  FINALIZE_STATUS                "success" or "failed"
  FAILED_REASON                  optional string (only used when status=failed)
"""

import hashlib
import hmac as hmaclib
import json
import os
import sys
import uuid
from datetime import datetime, timezone


def iso8601_now() -> str:
    """Return current UTC time in ISO 8601 with offset (+00:00), matching PHP Carbon::toIso8601String()."""
    return datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S+00:00')


def generate_signature(secret: str, method: str, path: str, body: str) -> dict:
    """
    Replicate PHP SignatureService::generateSignature().

    Signing string:
        METHOD\nPATH\nTIMESTAMP\nNONCE\nBODY
    Signature: HMAC-SHA256 hex digest.
    """
    timestamp = iso8601_now()
    nonce = str(uuid.uuid4())
    signing_string = "\n".join([method, path, timestamp, nonce, body])
    sig = hmaclib.new(
        secret.encode('utf-8'),
        signing_string.encode('utf-8'),
        hashlib.sha256,
    ).hexdigest()
    return {
        'signature': sig,
        'timestamp': timestamp,
        'nonce': nonce,
        'algorithm': 'hmac-sha256',
    }


def main() -> int:
    # ── required Peldarg secrets (from GitHub Actions secrets) ──────────────
    base_url = os.getenv('PELDARG_BASE_URL', '').rstrip('/')
    token = os.getenv('PELDARG_PARTNER_TOKEN', '')
    partner_name = os.getenv('PELDARG_PARTNER_NAME', 'riskcontrol')
    sig_secret = os.getenv('PELDARG_PARTNER_SIGNATURE_SECRET', '')

    if not base_url or not token or not sig_secret:
        print('[peldarg_finalize] PELDARG_BASE_URL / PELDARG_PARTNER_TOKEN / '
              'PELDARG_PARTNER_SIGNATURE_SECRET not set — skipping finalize.')
        # Exit 0: missing config is not a processing failure; RCS will still attempt finalize.
        return 0

    # ── job context ──────────────────────────────────────────────────────────
    partner_request_id = os.getenv('PARTNER_REQUEST_ID', '').strip()
    if not partner_request_id:
        print('[peldarg_finalize] PARTNER_REQUEST_ID is empty — skipping finalize.')
        return 0

    finalize_status = os.getenv('FINALIZE_STATUS', 'success').strip() or 'success'
    pages_processed = int(os.getenv('PAGES_PROCESSED', '0') or '0')
    pages_with_results = int(os.getenv('PAGES_WITH_RESULTS', '0') or '0')
    failed_reason: str | None = os.getenv('FAILED_REASON', '').strip() or None

    path = '/api/partner/finalize-extraction'

    payload = {
        'partner_request_id': partner_request_id,
        'status': finalize_status,
        'pages_processed': pages_processed,
        'pages_with_results': pages_with_results,
        'failed_reason': failed_reason,
    }

    body = json.dumps(payload)
    sig_info = generate_signature(sig_secret, 'POST', path, body)

    headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Partner-Token': token,
        'X-Partner-Name': partner_name,
        'X-Partner-Signature': sig_info['signature'],
        'X-Partner-Timestamp': sig_info['timestamp'],
        'X-Partner-Nonce': sig_info['nonce'],
        'X-Signature-Algorithm': sig_info['algorithm'],
        'Idempotency-Key': str(uuid.uuid4()),
    }

    url = base_url + path
    print(f'[peldarg_finalize] POST {url}')
    print(f'[peldarg_finalize] partner_request_id={partner_request_id}')
    print(f'[peldarg_finalize] status={finalize_status} pages_processed={pages_processed} pages_with_results={pages_with_results}')

    try:
        import requests  # noqa: PLC0415 — imported here so missing pkg gives a clear error
        resp = requests.post(url, data=body, headers=headers, timeout=30)
        print(f'[peldarg_finalize] Response HTTP {resp.status_code}')
        try:
            print('[peldarg_finalize] Response body:', resp.text[:500])
        except Exception:
            pass

        if resp.ok:
            result = resp.json()
            consumed = result.get('credits_consumed', '?')
            refunded = result.get('credits_refunded', '?')
            credit_status = result.get('status', '?')
            print(f'[peldarg_finalize] ✓ credits_consumed={consumed} credits_refunded={refunded} status={credit_status}')
            return 0
        else:
            print(f'[peldarg_finalize] ✗ Peldarg returned HTTP {resp.status_code} — credits may not be deducted.')
            # Exit non-zero so GitHub Actions marks the step as failed (visible in run log).
            return 1

    except Exception as e:
        print(f'[peldarg_finalize] ✗ Exception calling Peldarg: {repr(e)}')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
