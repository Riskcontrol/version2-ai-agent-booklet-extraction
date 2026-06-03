const API = {
  summary: '/api/credit-summary',
  history: '/api/payment-history',
  initialize: '/api/top-up/paystack/initialize',
  verify: '/api/top-up/paystack/verify',
}

function $(sel) { return document.querySelector(sel) }

function csrfToken() {
  return document.querySelector('input[name="_token"]')?.value || ''
}

function moneyFmtUSD(v) {
  const n = Number(v || 0)
  return Number.isFinite(n) ? n.toFixed(2) : '0.00'
}

function moneyFmtNGN(v) {
  const n = Number(v || 0)
  if (!Number.isFinite(n)) return '0'
  try {
    return Math.round(n).toLocaleString()
  } catch {
    return String(Math.round(n))
  }
}

function parseNumberLike(value, fallback = 0) {
  if (typeof value === 'number') return Number.isFinite(value) ? value : fallback
  const raw = String(value ?? '').trim()
  if (!raw) return fallback
  const cleaned = raw.replace(/[^0-9.-]/g, '')
  const parsed = Number.parseFloat(cleaned)
  return Number.isFinite(parsed) ? parsed : fallback
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}

function formatIsoDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString()
}

let pricing = {
  unitPriceUsd: 0,
  fxRateNgn: 0,
}

function showMessage(text, tone = 'text-gray-600') {
  const msg = $('#topUpMsg')
  if (!msg) return
  msg.textContent = text || ''
  msg.className = `mt-3 text-sm ${tone}`
}

function showPaymentHistoryMessage(text, tone = 'text-gray-600') {
  const msg = $('#paymentHistoryMsg')
  if (!msg) return
  msg.textContent = text || ''
  msg.className = `mt-3 text-sm ${tone}`
}

function requestedCredits() {
  const raw = String($('#requested_credits')?.value || '0')
  const parsed = Number.parseInt(raw, 10)
  return Number.isFinite(parsed) ? parsed : 0
}

function computeTopUpEstimate() {
  const credits = requestedCredits()
  const amountUsd = credits > 0 ? credits * pricing.unitPriceUsd : 0
  const amountNgn = amountUsd * pricing.fxRateNgn

  if ($('#topUpAmountUsd')) $('#topUpAmountUsd').textContent = `$${moneyFmtUSD(amountUsd)}`
  if ($('#topUpAmountNgn')) $('#topUpAmountNgn').textContent = `₦${moneyFmtNGN(amountNgn)}`

  const unitNgn = pricing.unitPriceUsd * pricing.fxRateNgn
  if ($('#unitPriceNgn')) $('#unitPriceNgn').textContent = `₦${moneyFmtNGN(unitNgn)}`
  if ($('#pricingNoteFx')) $('#pricingNoteFx').textContent = moneyFmtNGN(pricing.fxRateNgn)
  if ($('#pricingNoteUnitUsd')) $('#pricingNoteUnitUsd').textContent = moneyFmtUSD(pricing.unitPriceUsd)
  if ($('#pricingNoteUnitNgn')) $('#pricingNoteUnitNgn').textContent = moneyFmtNGN(unitNgn)
}

function setHistorySummary(nodeId, summary) {
  const node = document.getElementById(nodeId)
  if (!node) return
  const count = Number(summary?.invoice_count ?? 0)
  const credits = Number(summary?.requested_credits ?? 0)
  const amountUsd = moneyFmtUSD(summary?.requested_amount_usd ?? 0)
  node.textContent = `${count} payments | ${credits} credits | $${amountUsd}`
}

function renderLedgerEntries(entries) {
  if (!Array.isArray(entries) || entries.length === 0) {
    return '<span class="text-xs text-gray-500">No ledger entries</span>'
  }

  const rows = entries.map((entry) => {
    const amount = entry.amount_usd !== null && entry.amount_usd !== undefined
      ? `$${moneyFmtUSD(entry.amount_usd)}`
      : '—'

    return `<li class="text-xs text-gray-700">${escapeHtml(entry.action_type)} | ${escapeHtml(entry.credits)} credits | ${escapeHtml(amount)} | ${escapeHtml(formatIsoDate(entry.created_at))}</li>`
  }).join('')

  return `<details><summary class="cursor-pointer text-xs font-medium text-[#0a2912]">Ledger entries</summary><ul class="mt-2 space-y-1">${rows}</ul></details>`
}

function renderPaymentHistory(items) {
  const tbody = document.querySelector('#paymentHistoryTable tbody')
  if (!tbody) return

  if (!Array.isArray(items) || items.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="p-3 text-sm text-gray-500">No payment history found for the selected period.</td></tr>'
    return
  }

  tbody.innerHTML = items.map((item) => {
    const paymentRef = item.payment_reference || item.gateway_reference || '—'
    const logsHref = item.payment_reference
      ? `/logs?payment_reference=${encodeURIComponent(String(item.payment_reference))}`
      : null

    return `
      <tr class="border-b border-gray-100 align-top">
        <td class="p-2 text-gray-700">${escapeHtml(formatIsoDate(item.payment_at || item.fulfilled_at || item.paid_at || item.created_at))}</td>
        <td class="p-2 text-gray-900 font-medium">${escapeHtml(item.invoice_number)}</td>
        <td class="p-2 text-gray-700">${escapeHtml(paymentRef)}</td>
        <td class="p-2 text-gray-700">${escapeHtml(item.requested_credits)}</td>
        <td class="p-2 text-gray-700">$${escapeHtml(moneyFmtUSD(item.requested_amount_usd))}</td>
        <td class="p-2 text-gray-700">${escapeHtml(item.status || item.gateway_status || '—')}</td>
        <td class="p-2 text-gray-700">
          <div class="space-y-2">
            ${renderLedgerEntries(item.ledger_entries)}
            ${logsHref ? `<a href="${logsHref}" class="inline-flex text-xs font-medium text-[#0a2912] hover:underline">View matching documents</a>` : '<span class="text-xs text-gray-500">No document link</span>'}
          </div>
        </td>
      </tr>
    `
  }).join('')
}

async function loadCreditSummary() {
  try {
    const r = await fetch(API.summary, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (r.status === 401) {
      window.location.href = '/login'
      return
    }

    const data = await r.json()
    if (!r.ok) throw new Error(data?.error || data?.message || 'Unable to load credit summary.')

    const balance = Number.parseInt(String(data.credit_balance ?? '0'), 10)
    pricing.unitPriceUsd = parseNumberLike(data.unit_price_usd, 0)
    pricing.fxRateNgn = parseNumberLike(data.fx_rate_ngn, 0)

    if ($('#creditBalance')) $('#creditBalance').textContent = String(Number.isFinite(balance) ? balance : 0)
    if ($('#unitPriceUsd')) $('#unitPriceUsd').textContent = String(moneyFmtUSD(pricing.unitPriceUsd))
    if ($('#fxRateNgn')) $('#fxRateNgn').textContent = String(moneyFmtNGN(pricing.fxRateNgn))
    if ($('#billingAuthority')) $('#billingAuthority').textContent = 'Google Cloud'
    if ($('#creditSummaryMsg')) $('#creditSummaryMsg').textContent = ''

    computeTopUpEstimate()

    showMessage('Credit summary synced from Google Cloud.', 'text-green-700')
  } catch (err) {
    if ($('#creditSummaryMsg')) {
      $('#creditSummaryMsg').textContent = err?.message || 'Unable to load credit summary.'
      $('#creditSummaryMsg').className = 'mt-2 text-xs text-red-600'
    }
    showMessage(err?.message || 'Unable to load credit summary.', 'text-red-600')
  }
}

async function loadPaymentHistory() {
  const params = new URLSearchParams()
  const year = String($('#paymentHistoryYear')?.value || '').trim()
  const month = String($('#paymentHistoryMonth')?.value || '').trim()

  if (year) params.set('year', year)
  if (month) params.set('month', month)
  // Pass the browser's UTC offset so the server can filter by local-timezone month
  // boundaries instead of raw UTC MONTH(). getTimezoneOffset() returns minutes
  // west of UTC (negative for east-of-UTC zones like WAT/UTC+1 = -60).
  params.set('tz_offset', String(new Date().getTimezoneOffset()))

  showPaymentHistoryMessage('Loading payment history...', 'text-gray-600')

  try {
    const url = params.toString() ? `${API.history}?${params.toString()}` : API.history
    const r = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })

    if (r.status === 401) {
      window.location.href = '/login'
      return
    }

    const data = await r.json()
    if (!r.ok) throw new Error(data?.error || data?.message || 'Unable to load payment history.')

    setHistorySummary('paymentHistorySelectedPeriod', data?.summary?.selected_period)
    setHistorySummary('paymentHistoryCurrentMonth', data?.summary?.current_month)
    setHistorySummary('paymentHistoryCurrentYear', data?.summary?.current_year)
    renderPaymentHistory(data?.items || [])
    showPaymentHistoryMessage('Payment history synced from Google Cloud.', 'text-green-700')
  } catch (err) {
    renderPaymentHistory([])
    showPaymentHistoryMessage(err?.message || 'Unable to load payment history.', 'text-red-600')
  }
}

async function verifyReference(reference) {
  const token = csrfToken()
  if (!token || !reference) return

  showMessage('Verifying payment...', 'text-gray-600')

  const r = await fetch(API.verify, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'X-CSRF-TOKEN': token,
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify({ reference }),
  })

  const data = await r.json()
  if (!r.ok) {
    throw new Error(data?.error || data?.message || 'Verification failed.')
  }

  await loadCreditSummary()
  const reusedText = data?.already_fulfilled ? ' Payment was already fulfilled earlier.' : ''
  showMessage(`Payment verified successfully.${reusedText}`, 'text-green-700')
}

async function openPaystackPopup() {
  const token = csrfToken()
  if (!token) {
    showMessage('Security token missing. Refresh the page.', 'text-red-600')
    return
  }

  const credits = requestedCredits()
  if (!Number.isFinite(credits) || credits < 1) {
    showMessage('Enter the number of credits you want first.', 'text-red-600')
    return
  }

  const payBtn = $('#paystackBtn')
  if (payBtn) payBtn.disabled = true

  try {
    showMessage('Initializing Paystack checkout...', 'text-gray-600')

    const r = await fetch(API.initialize, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': token,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ requested_credits: credits }),
    })

    const data = await r.json()
    if (!r.ok) throw new Error(data?.error || data?.message || 'Unable to initialize checkout.')

    // Redirect to Paystack's hosted checkout page. Paystack will redirect back to
    // /top-up?reference=... (the callback_url set on the server) after payment so
    // the page-load handler can auto-verify. Using authorization_url avoids all
    // cross-origin/key-mismatch issues with the inline popup.
    if (data.authorization_url) {
      showMessage('Redirecting to Paystack checkout...', 'text-gray-600')
      window.location.href = String(data.authorization_url)
      return
    }

    throw new Error('Paystack checkout URL unavailable. Please try again.')
  } catch (err) {
    showMessage(err?.message || 'Unable to start Paystack checkout.', 'text-red-600')
    if (payBtn) payBtn.disabled = false
  }
}

document.addEventListener('DOMContentLoaded', async () => {
  const y = document.getElementById('year')
  if (y) y.textContent = new Date().getFullYear()

  const now = new Date()
  const yearNode = $('#paymentHistoryYear')
  const monthNode = $('#paymentHistoryMonth')
  if (yearNode && !yearNode.value) yearNode.value = String(now.getFullYear())
  if (monthNode && !monthNode.value) monthNode.value = String(now.getMonth() + 1)

  const creditsNode = $('#requested_credits')
  if (creditsNode) creditsNode.addEventListener('input', computeTopUpEstimate)

  const historyFilters = $('#paymentHistoryFilters')
  if (historyFilters) {
    historyFilters.addEventListener('submit', async (event) => {
      event.preventDefault()
      await loadPaymentHistory()
    })
  }

  const payBtn = $('#paystackBtn')
  if (payBtn) payBtn.addEventListener('click', async () => {
    await openPaystackPopup()
  })

  await loadCreditSummary()
  await loadPaymentHistory()

  const paystackRef = new URLSearchParams(window.location.search).get('reference')
    || new URLSearchParams(window.location.search).get('trxref')
  if (paystackRef) {
    try {
      await verifyReference(paystackRef)
      await loadPaymentHistory()
      const url = new URL(window.location.href)
      url.searchParams.delete('reference')
      url.searchParams.delete('trxref')
      window.history.replaceState({}, document.title, url.toString())
    } catch (err) {
      showMessage(err?.message || 'Unable to verify checkout reference.', 'text-red-600')
    }
  }
})
