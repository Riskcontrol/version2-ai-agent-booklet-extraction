const $ = (id) => document.getElementById(id)
const node = (tag, text, cls = '') => {
  const element = document.createElement(tag)
  element.textContent = text
  element.className = cls
  return element
}
let batch = null
let balance = null
let pollTimer = null
let batchPage = 1
let resultPage = 1
let unmatchedPage = 1
let auditPage = 1
let reportOptionsPage = 1
let reviewing = null
let chosenReport = null
let uploading = false
let selectionVersion = 0

async function api(path, options = {}) {
  const response = await fetch(path, {
    credentials: 'same-origin', ...options,
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      ...(options.headers || {}) },
  })
  if (response.status === 401) throw new Error('Your session expired. Log in again before starting a new upload.')
  const json = await response.json().catch(() => null)
  if (!response.ok) throw new Error(json?.message || `Request failed (${response.status}). No retry was attempted.`)
  if (json === null) throw new Error('Unexpected server response. Check your login before starting a new upload.')
  return json
}
const post = (path, body = {}) => api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
const base = () => `/api/verifications/${encodeURIComponent(batch.id)}`
const terminal = () => batch && ['complete', 'failed'].includes(batch.status)
function stopPolling() { clearTimeout(pollTimer); pollTimer = null }
function fail(error) { $('pageMessage').textContent = error.message || String(error) }
function busy(button, value) { button.disabled = value }
function pagination(data, prefix) {
  $(`previous${prefix}`).disabled = !data.prev_page_url
  $(`next${prefix}`).disabled = !data.next_page_url
  const label = { Batches: 'batchPage', Results: 'resultPage', Unmatched: 'unmatchedPage', Audits: 'auditPage', ReportOptions: 'reportOptionsPage' }[prefix]
  $(label).textContent = `${data.current_page} / ${data.last_page}`
}

async function loadBalance() {
  const summary = await api('/api/credit-summary')
  balance = Number(summary.credit_balance)
  $('balance').textContent = `Available credits: ${Number.isFinite(balance) ? balance : 'unavailable'}`
}

async function loadBatches() {
  const payload = await api(`/api/verifications?page=${batchPage}`)
  $('batches').replaceChildren()
  if (!payload.data.length) $('batches').append(node('p', 'No batches yet.', 'text-sm text-gray-500'))
  for (const item of payload.data) {
    const button = node('button', `${item.filename} — ${item.status} — ${item.pages_requested} pages`, 'w-full text-left border border-gray-200 rounded-lg p-3 text-sm hover:bg-lime-50')
    button.type = 'button'
    button.addEventListener('click', () => selectBatch(item.id).catch(fail))
    $('batches').append(button)
  }
  pagination(payload, 'Batches')
}

async function selectBatch(id) {
  stopPolling()
  ++selectionVersion
  batch = { id }
  resultPage = unmatchedPage = auditPage = 1
  $('searchResults').value = ''
  $('statusFilter').value = ''
  $('pageMessage').textContent = ''
  await loadBatch()
}

async function loadBatch() {
  if (!batch) return
  const id = batch.id
  const version = selectionVersion
  const payload = await api(`/api/verifications/${encodeURIComponent(id)}`)
  if (version !== selectionVersion) return
  batch = payload.batch
  $('batchPanel').classList.remove('hidden')
  $('batchTitle').textContent = batch.filename
  $('batchStatus').textContent = `Status: ${batch.status}. Processed ${batch.pages_processed} / ${batch.pages_requested} pages in ${batch.files_processed} PDF files. Extracted qualification records: ${payload.records_extracted}.`
  $('batchFailure').textContent = batch.failed_reason || ''
  if (batch.credit_status === 'settlement_failed' || batch.credit_status === 'authorization_failed') {
    $('batchFailure').textContent += ' Billing could not be confirmed. Ask your administrator to check the billing history; no automatic retry will occur.'
  }
  if (batch.status === 'uploading' || batch.status === 'upload_failed') {
    $('batchFailure').textContent = 'This upload is incomplete. Select the ZIP above and start a new upload; interrupted uploads do not resume.'
  }
  $('inventorySummary').textContent = `${batch.inventory?.length || 0} PDF files. ${batch.pages_requested} PDF pages. ${batch.pages_requested} credits required for this batch. Reserved: ${batch.credits_reserved}; consumed: ${batch.credits_consumed}; refunded: ${batch.credits_refunded}. Billing status: ${batch.credit_status}.`
  $('startBatch').classList.toggle('hidden', batch.status !== 'ready')
  $('startBatch').disabled = batch.status !== 'ready' || balance === null || balance < batch.pages_requested
  $('startBatch').textContent = balance !== null && balance < batch.pages_requested
    ? `Need ${batch.pages_requested} credits; available ${balance}` : `Confirm ${batch.pages_requested} credits and start comparison`
  $('notices').replaceChildren(...(batch.notices || []).map(text => node('li', text)))
  $('inventory').replaceChildren(...(batch.inventory || []).map(item => node('li', `${item.filename} — ${item.pages} page(s)`)))
  $('resultCounts').replaceChildren(...['Yes', 'Disparity', 'Needs review'].map(status => node('span', `${status}: ${payload.summary[status] || 0}`, 'border rounded-lg p-2')),
    node('span', `Unmatched reports: ${payload.unmatched_reports}`, 'border rounded-lg p-2'))
  $('downloads').classList.toggle('hidden', !terminal())
  $('downloadCsv').href = `${base()}/export/csv`
  $('downloadXlsx').href = `${base()}/export/xlsx`
  await loadResults()
  if ($('unmatchedPanel').open) await loadUnmatched()
  if ($('auditPanel').open) await loadAudits()
  stopPolling()
  if (['inventory_queued', 'inventory', 'authorizing', 'queued', 'processing'].includes(batch.status)) {
    pollTimer = setTimeout(() => loadBatch().catch(error => { stopPolling(); fail(error) }), 5000)
  }
}

async function loadResults() {
  if (!batch) return
  const version = selectionVersion
  const parameters = new URLSearchParams({ page: resultPage, q: $('searchResults').value.trim(), status: $('statusFilter').value })
  const payload = await api(`${base()}/results?${parameters}`)
  if (version !== selectionVersion) return
  $('results').replaceChildren()
  for (const result of payload.data) {
    const row = node('tr', '', 'border-b border-gray-200 align-top')
    const name = result.certificate.fields.name || '(Name unreadable)'
    row.append(node('td', name, 'p-3'), node('td', result.certificate.fields.qualification || '', 'p-3'),
      node('td', result.report?.fields.name || 'Unpaired', 'p-3'), node('td', result.status, 'p-3 font-semibold'), node('td', result.reason, 'p-3'))
    const cell = node('td', '', 'p-3')
    const button = node('button', 'Review', 'underline')
    button.disabled = !terminal()
    button.addEventListener('click', () => openReview(result).catch(fail))
    cell.append(button); row.append(cell); $('results').append(row)
  }
  if (!payload.data.length) {
    const row = node('tr', '')
    const cell = node('td', terminal() ? 'No certificate results match these filters.' : 'Results appear after processing finishes. Completed extraction records are retained if processing fails.', 'p-3 text-gray-500')
    cell.colSpan = 6; row.append(cell); $('results').append(row)
  }
  pagination(payload, 'Results')
}

function reportLabel(report) {
  return `${report.fields.name || '(Name unreadable)'} — ${report.fields.qualification || '(Award unreadable)'} — ${report.fields.session || ''} — ${report.filename}, page ${report.page}`
}
const sourceUrl = (record) => `${base()}/sources/${record.source_id}#page=${record.page}`
async function loadUnmatched() {
  if (!batch) return
  const payload = await api(`${base()}/records?unmatched=1&page=${unmatchedPage}`)
  $('unmatchedReports').replaceChildren()
  for (const report of payload.data) {
    const link = node('a', reportLabel(report), 'block underline')
    link.href = sourceUrl(report); link.target = '_blank'; link.rel = 'noopener'; $('unmatchedReports').append(link)
  }
  if (!payload.data.length) $('unmatchedReports').append(node('p', 'No unmatched reports.'))
  pagination(payload, 'Unmatched')
}
async function loadAudits() {
  if (!batch) return
  const payload = await api(`${base()}/audits?page=${auditPage}`)
  $('auditHistory').replaceChildren(...payload.data.map(a => node('p', `${a.created_at} — ${a.user_email}: certificate ${a.certificate_id}, report ${a.previous_report_id || 'none'} → ${a.report_id || 'none'}. ${a.note}`, 'border-b py-2')))
  if (!payload.data.length) $('auditHistory').append(node('p', 'No pairing corrections.'))
  pagination(payload, 'Audits')
}

function previewReport(report) {
  chosenReport = report
  $('reportSource').textContent = report ? `${report.filename}, page ${report.page}` : 'No report selected.'
  $('reportPreview').src = report ? sourceUrl(report) : 'about:blank'
  $('openReport').href = report ? sourceUrl(report) : '#'
  $('openReport').hidden = !report
  $('selectedReport').textContent = report ? `Selected report: ${reportLabel(report)}` : 'Selected report: none. This certificate will remain Needs review.'
}
function reportButton(report, text = '') {
  const button = node('button', (text ? `${text}: ` : '') + reportLabel(report), 'w-full text-left border rounded-lg p-2 hover:bg-lime-50')
  button.type = 'button'; button.addEventListener('click', () => previewReport(report))
  return button
}
async function openReview(result) {
  stopPolling()
  reviewing = result
  $('reviewTitle').textContent = `Review: ${result.certificate.fields.name || 'Certificate'}`
  $('reviewReason').textContent = result.reason
  $('certificateSource').textContent = `${result.certificate.filename}, page ${result.certificate.page}`
  $('certificatePreview').src = sourceUrl(result.certificate)
  $('openCertificate').href = sourceUrl(result.certificate)
  previewReport(result.report)
  $('fieldComparisons').replaceChildren()
  for (const field of result.comparisons) {
    const row = node('tr', '', 'border-b')
    row.append(...[field.field, field.certificate, field.report, field.state].map(value => node('td', value, 'p-2')))
    $('fieldComparisons').append(row)
  }
  $('suggestedReports').replaceChildren()
  const candidates = result.candidates || []
  for (const candidate of candidates) {
    // Fetch by ID within this owned batch, never by global record ID.
    const payload = await api(`${base()}/records?ids=${candidate.report_id}`)
    const report = payload.data.find(r => r.id === candidate.report_id)
    if (report) $('suggestedReports').append(reportButton(report, candidate.reason))
  }
  $('pairingNote').value = ''
  $('reviewMessage').textContent = ''
  $('reportSearch').value = ''
  reportOptionsPage = 1
  $('reviewDialog').showModal()
  await loadReportOptions()
}
async function loadReportOptions() {
  const parameters = new URLSearchParams({ q: $('reportSearch').value.trim(), page: reportOptionsPage })
  const payload = await api(`${base()}/records?${parameters}`)
  $('reportOptions').replaceChildren(...payload.data.map(report => reportButton(report)))
  if (!payload.data.length) $('reportOptions').append(node('p', 'No reports found.'))
  pagination(payload, 'ReportOptions')
}

$('zipForm').addEventListener('submit', async event => {
  event.preventDefault()
  if (uploading) return
  const file = $('zipFile').files[0]
  if (!file) return
  uploading = true
  busy($('uploadZip'), true)
  $('zipFile').disabled = true
  $('uploadProgress').classList.remove('hidden')
  $('uploadProgress').value = 0
  $('uploadMessage').textContent = 'Uploading ZIP. Keep this page open; interrupted uploads cannot resume.'
  stopPolling()
  try {
    const created = await post('/api/verifications', { filename: file.name, size: file.size })
    const id = created.batch.id
    const chunkSize = created.chunk_bytes
    if (!Number.isInteger(chunkSize) || chunkSize < 1) throw new Error('Invalid server upload configuration.')
    for (let offset = 0; offset < file.size; offset += chunkSize) {
      await api(`/api/verifications/${id}/chunks`, { method: 'POST', headers: { 'Content-Type': 'application/octet-stream', 'X-Upload-Offset': String(offset) }, body: file.slice(offset, offset + chunkSize) })
      $('uploadProgress').value = Math.round(Math.min(offset + chunkSize, file.size) / file.size * 100)
    }
    await post(`/api/verifications/${id}/finish-upload`)
    $('uploadMessage').textContent = 'ZIP uploaded. Check its inventory and comparison status below.'
    $('zipForm').reset()
    await loadBatches()
    await selectBatch(id)
  } catch (error) {
    $('uploadMessage').textContent = `${error.message} Upload the ZIP again to create a new batch. No retry or resume was attempted.`
  } finally {
    uploading = false
    busy($('uploadZip'), false)
    $('zipFile').disabled = false
  }
})
$('startBatch').addEventListener('click', async () => {
  if (!batch || batch.status !== 'ready') return
  busy($('startBatch'), true)
  $('pageMessage').textContent = ''
  try {
    await post(`${base()}/start`, { confirm_credits: batch.pages_requested })
    await loadBalance()
    await loadBatch()
    await loadBatches()
  } catch (error) { fail(error); await loadBatch().catch(fail) }
})
$('resultFilters').addEventListener('submit', event => { event.preventDefault(); resultPage = 1; loadResults().catch(fail) })
$('refreshBatches').addEventListener('click', () => loadBatches().catch(fail))
$('refreshBatch').addEventListener('click', () => loadBatch().catch(fail))
$('unmatchedPanel').addEventListener('toggle', () => { if ($('unmatchedPanel').open) loadUnmatched().catch(fail) })
$('auditPanel').addEventListener('toggle', () => { if ($('auditPanel').open) loadAudits().catch(fail) })
$('closeReview').addEventListener('click', () => $('reviewDialog').close())
$('unpairReport').addEventListener('click', () => previewReport(null))
$('reportSearchForm').addEventListener('submit', event => { event.preventDefault(); reportOptionsPage = 1; loadReportOptions().catch(error => { $('reviewMessage').textContent = error.message }) })
$('pairingForm').addEventListener('submit', async event => {
  event.preventDefault()
  if (!reviewing) return
  busy($('savePairing'), true)
  try {
    await post(`${base()}/pairings/${reviewing.id}`, { report_id: chosenReport?.id ?? null, revision: batch.revision, note: $('pairingNote').value.trim() })
    $('reviewDialog').close()
    await loadBatch()
  } catch (error) { $('reviewMessage').textContent = error.message }
  finally { busy($('savePairing'), false) }
})
for (const [prefix, change, load] of [
  ['Batches', delta => { batchPage += delta }, loadBatches], ['Results', delta => { resultPage += delta }, loadResults],
  ['Unmatched', delta => { unmatchedPage += delta }, loadUnmatched], ['Audits', delta => { auditPage += delta }, loadAudits],
  ['ReportOptions', delta => { reportOptionsPage += delta }, loadReportOptions],
]) {
  $(`previous${prefix}`).addEventListener('click', () => { change(-1); load().catch(fail) })
  $(`next${prefix}`).addEventListener('click', () => { change(1); load().catch(fail) })
}
window.addEventListener('pagehide', stopPolling)
Promise.allSettled([loadBalance(), loadBatches()]).then(results => {
  for (const result of results) if (result.status === 'rejected') fail(result.reason)
})
