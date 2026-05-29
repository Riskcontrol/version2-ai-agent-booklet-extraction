import { PDFDocument } from 'pdf-lib'

const API = {
  upload: '/api/certificates/upload',
  list: '/api/certificates',
  summary: '/api/credit-summary',
  delete: (id) => `/api/certificates/${id}`,
}

let totalPagesDetected = null
let countingPages = false
let availableCredits = 0

function $(sel) { return document.querySelector(sel) }
function el(tag, attrs = {}) { const e = document.createElement(tag); Object.assign(e, attrs); return e }

function updateCreditPreview(totalPages){
  const totalNode = document.getElementById('totalPages')
  const selectedNode = document.getElementById('pagesSelected')
  const neededNode = document.getElementById('neededCredits')
  const value = Number.isFinite(totalPages) && totalPages > 0 ? String(totalPages) : '-'

  if (totalNode) totalNode.textContent = value
  if (selectedNode) selectedNode.textContent = value
  if (neededNode) neededNode.textContent = value
}

function setGateMessage({ text, tone }) {
  const gate = document.getElementById('creditGateMsg')
  if (!gate) return

  if (!text) {
    gate.textContent = ''
    gate.classList.add('hidden')
    gate.classList.remove('text-red-600', 'text-amber-700', 'text-gray-700')
    return
  }

  gate.textContent = text
  gate.classList.remove('hidden', 'text-red-600', 'text-amber-700', 'text-gray-700')
  gate.classList.add(tone || 'text-gray-700')
}

async function detectPdfPages(file){
  const buf = await file.arrayBuffer()
  const pdf = await PDFDocument.load(buf, { ignoreEncryption: true })
  return pdf.getPageCount()
}

function currentNeededCredits() {
  if (!Number.isFinite(totalPagesDetected) || totalPagesDetected < 1) return null
  return totalPagesDetected
}

function updateUploadGate() {
  const uploadBtn = $('#uploadBtn')
  const file = $('#file')?.files?.[0]
  if (!uploadBtn) return

  if (!file) {
    uploadBtn.disabled = true
    setGateMessage({ text: '', tone: null })
    return
  }

  if (countingPages) {
    uploadBtn.disabled = true
    setGateMessage({ text: 'Counting PDF pages...', tone: 'text-gray-700' })
    return
  }

  const neededCredits = currentNeededCredits()
  if (!neededCredits) {
    uploadBtn.disabled = true
    setGateMessage({ text: 'Unable to compute page count for credit check.', tone: 'text-red-600' })
    return
  }

  if (availableCredits < neededCredits) {
    uploadBtn.disabled = true
    setGateMessage({
      text: `Need ${neededCredits} credits, you have ${availableCredits}.`,
      tone: 'text-red-600',
    })
    return
  }

  uploadBtn.disabled = false
  setGateMessage({
    text: `Need ${neededCredits} credits, you have ${availableCredits}.`,
    tone: 'text-amber-700',
  })
}

// ---------------------------------------------------------------------------
// Adaptive polling
// ---------------------------------------------------------------------------
let pollTimer = null
let pollInFlight = false
let pollDelayMs = 4000
let authRedirectScheduled = false
let creditSummaryTimer = null

function hasProcessing(list) {
  return Array.isArray(list) && list.some(d => String(d?.status || '').toLowerCase() === 'processing')
}

function stopPolling() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
  pollDelayMs = 4000
}

function startCreditSummaryPolling() {
  stopCreditSummaryPolling()
  creditSummaryTimer = setInterval(() => {
    if (!document.hidden) loadCreditSummary()
  }, 30000)
}

function stopCreditSummaryPolling() {
  if (creditSummaryTimer) clearInterval(creditSummaryTimer)
  creditSummaryTimer = null
}

function scheduleNextPoll(nextDelayMs) {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = setTimeout(() => {
    if (!pollInFlight) loadDocs({ fromPoll: true })
    else scheduleNextPoll(Math.min((nextDelayMs || pollDelayMs) + 2000, 30000))
  }, nextDelayMs)
}

function adjustDelay({ anyProcessing, fromPoll }) {
  const hidden = document.hidden === true
  if (!anyProcessing) return null
  if (!fromPoll) {
    pollDelayMs = 3000
  } else {
    pollDelayMs = Math.min(Math.round(pollDelayMs * 1.25), 30000)
  }
  if (hidden) pollDelayMs = Math.max(pollDelayMs, 20000)
  return pollDelayMs
}

function handleUnauthorized() {
  if (authRedirectScheduled) return
  authRedirectScheduled = true
  stopPolling()
  const msg = $('#uploadMsg')
  if (msg) {
    msg.textContent = 'Your session has expired. Redirecting to login...'
    msg.className = 'mt-3 text-sm text-red-600'
  }
  setTimeout(() => { window.location.href = '/login' }, 800)
}

function buildFilters() {
  const params = new URLSearchParams()
  const status = $('#filter_status')?.value?.trim()
  const creditStatus = $('#filter_credit_status')?.value?.trim()
  const dateFrom = $('#filter_date_from')?.value?.trim()
  const dateTo = $('#filter_date_to')?.value?.trim()
  const query = $('#filter_q')?.value?.trim()
  const user = $('#filter_user')?.value?.trim()
  const requestId = $('#filter_request_id')?.value?.trim()
  const paymentReference = $('#filter_payment_reference')?.value?.trim()
  const extractionType = $('#filter_extraction_type')?.value?.trim()

  if (status) params.set('status', status)
  if (creditStatus) params.set('credit_status', creditStatus)
  if (dateFrom) params.set('date_from', dateFrom)
  if (dateTo) params.set('date_to', dateTo)
  if (query) params.set('q', query)
  if (user) params.set('user', user)
  if (requestId) params.set('request_id', requestId)
  if (paymentReference) params.set('payment_reference', paymentReference)
  if (extractionType) params.set('extraction_type', extractionType)

  return params
}

function syncFilterUrl() {
  const params = buildFilters()
  const next = params.toString()
  const url = next ? `${window.location.pathname}?${next}` : window.location.pathname
  window.history.replaceState({}, '', url)
}

function hydrateFiltersFromUrl() {
  const params = new URLSearchParams(window.location.search)
  const map = {
    filter_status: 'status',
    filter_credit_status: 'credit_status',
    filter_date_from: 'date_from',
    filter_date_to: 'date_to',
    filter_q: 'q',
    filter_user: 'user',
    filter_request_id: 'request_id',
    filter_payment_reference: 'payment_reference',
    filter_extraction_type: 'extraction_type',
  }
  Object.entries(map).forEach(([id, key]) => {
    const node = document.getElementById(id)
    if (node && params.has(key)) node.value = params.get(key)
  })
}

async function loadCreditSummary() {
  const msg = $('#creditSummaryMsg')
  try {
    const r = await fetch(API.summary, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (r.status === 401) { handleUnauthorized(); return }
    if (!r.ok) throw new Error(`Credit summary failed (${r.status})`)
    const summary = await r.json()
    availableCredits = Number.isFinite(Number(summary.credit_balance)) ? Number(summary.credit_balance) : 0
    $('#creditBalance').textContent = String(summary.credit_balance ?? 0)
    $('#creditValue').textContent = `NGN ${(summary.credit_value_naira ?? 0).toLocaleString()}`
    $('#creditUnitPrice').textContent = `NGN ${(summary.unit_price_naira ?? 0).toLocaleString()} per page`
    $('#billingAuthority').textContent = 'Google Cloud'
    updateUploadGate()
    if (msg) {
      msg.textContent = 'Balance synced from Google Cloud billing authority.'
      msg.className = 'mt-2 text-xs text-green-700'
    }
  } catch (err) {
    if (msg) {
      msg.textContent = err.message || 'Unable to load credit summary.'
      msg.className = 'mt-2 text-xs text-red-600'
    }
  }
}

// ---------------------------------------------------------------------------
// DOMContentLoaded
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
  const y = document.getElementById('year'); if (y) y.textContent = new Date().getFullYear()
  hydrateFiltersFromUrl()
  loadDocs({ fromPoll: false })
  loadCreditSummary()
  startCreditSummaryPolling()

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && pollTimer) scheduleNextPoll(800)
    if (!document.hidden) loadCreditSummary()
  })

  window.addEventListener('beforeunload', () => {
    stopPolling()
    stopCreditSummaryPolling()
  })

  const up = $('#uploadForm')
  const fileNode = $('#file')

  const initialBalance = Number($('#creditBalance')?.textContent || '0')
  availableCredits = Number.isFinite(initialBalance) ? initialBalance : 0

  if (fileNode) {
    fileNode.addEventListener('change', async () => {
      const msg = $('#uploadMsg')
      const file = fileNode.files?.[0]
      totalPagesDetected = null
      updateCreditPreview(null)
      updateUploadGate()
      if (!file) return

      countingPages = true
      if (msg) {
        msg.textContent = 'Calculating PDF pages and needed credits...'
        msg.className = 'mt-3 text-sm text-gray-600'
      }

      try {
        const detected = await detectPdfPages(file)
        totalPagesDetected = detected > 0 ? detected : null
        updateCreditPreview(totalPagesDetected)
        updateUploadGate()

        if (msg && totalPagesDetected) {
          msg.textContent = `Detected ${totalPagesDetected} page(s). Needed credits are 1 credit per page.`
          msg.className = 'mt-3 text-sm text-green-700'
        }
      } catch {
        if (msg) {
          msg.textContent = 'Unable to auto-detect page count from this PDF. Upload stays disabled until page count is detected.'
          msg.className = 'mt-3 text-sm text-amber-700'
        }
        updateUploadGate()
      } finally {
        countingPages = false
        updateUploadGate()
      }
    })
  }

  updateUploadGate()

  if (up) up.addEventListener('submit', async (e) => {
    e.preventDefault()
    const f = $('#file').files[0]
    if (!f) return

    const maxUploadMb = Number(up.dataset.maxUploadMb || 0)
    const fileMb = f.size / (1024 * 1024)
    const msg = $('#uploadMsg')
    if (maxUploadMb > 0 && fileMb > maxUploadMb) {
      if (msg) {
        msg.textContent = `File is ${fileMb.toFixed(1)}MB, above server limit (${maxUploadMb}MB). Reduce PDF size or increase server upload limits.`
        msg.className = 'mt-3 text-sm text-red-600'
      }
      return
    }

    if (navigator.onLine === false) {
      if (msg) {
        msg.textContent = 'No internet connection. Reconnect and try upload again.'
        msg.className = 'mt-3 text-sm text-red-600'
      }
      return
    }

    if (countingPages) {
      if (msg) {
        msg.textContent = 'Please wait for page counting to finish.'
        msg.className = 'mt-3 text-sm text-amber-700'
      }
      return
    }

    updateUploadGate()
    if ($('#uploadBtn')?.disabled) return

    const csrfToken = document.querySelector('input[name="_token"]')?.value
    if (!csrfToken) {
      if (msg) {
        msg.textContent = 'Security token missing. Please refresh the page.'
        msg.className = 'mt-3 text-sm text-red-600'
      }
      return
    }

    const buildFormData = () => {
      const fd = new FormData()
      fd.append('file', f)
      const dateReceived = $('#date_received')?.value?.trim()
      const completedDate = $('#completed_date')?.value?.trim()
      const clientName = $('#client_name')?.value?.trim()
      if (dateReceived) fd.append('date_received', dateReceived)
      if (completedDate) fd.append('completed_date', completedDate)
      if (clientName) fd.append('client_name', clientName)
      return fd
    }

    const uploadBtn = $('#uploadBtn')
    const uploadProgress = $('#uploadProgress')
    const progressBar = $('#progressBar')
    const progressText = $('#progressText')

    uploadBtn.disabled = true
    uploadProgress.classList.remove('hidden')
    progressBar.style.width = '0%'
    progressText.textContent = 'Uploading PDF...'
    if (msg) msg.textContent = ''
    stopPolling()

    let progressInterval = null
    try {
      let progress = 0
      progressInterval = setInterval(() => {
        progress += 5
        if (progress <= 90) progressBar.style.width = progress + '%'
      }, 200)

      const r = await fetch(API.upload, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: buildFormData(),
      })

      if (r.status === 413) throw new Error('File is too large for server upload limit. Please reduce PDF size or increase server limits.')
      if (r.status === 401) { handleUnauthorized(); return }

      if (progressInterval) clearInterval(progressInterval)
      progressBar.style.width = '100%'

      const contentType = r.headers.get('content-type')
      if (!contentType || !contentType.includes('application/json')) {
        throw new Error('Server returned non-JSON response. Check if you are logged in.')
      }

      const result = await r.json()
      if (r.ok) {
        progressText.textContent = 'Upload complete! Processing...'
        if (msg) {
          msg.textContent = 'Queued for processing. Documents list will refresh automatically.'
          msg.className = 'mt-3 text-sm text-green-700'
        }
        setTimeout(() => {
          uploadProgress.classList.add('hidden')
          loadDocs({ fromPoll: false })
          loadCreditSummary()
          up.reset()
          uploadBtn.disabled = false
        }, 2000)
      } else {
        throw new Error(result.message || result.error || 'Upload failed')
      }
    } catch (err) {
      if (progressInterval) clearInterval(progressInterval)
      uploadProgress.classList.add('hidden')
      uploadBtn.disabled = false
      if (msg) {
        const errMsg = (err && err.message) ? String(err.message) : 'Unknown error'
        const networkMsg = (navigator.onLine === false || errMsg.includes('Failed to fetch'))
          ? 'Upload failed: network connection interrupted. Please reconnect and retry.'
          : `Upload failed: ${errMsg}`
        msg.textContent = networkMsg
        msg.className = 'mt-3 text-sm text-red-600'
      }
    }
  })

  const filterForm = $('#filterForm')
  if (filterForm) {
    filterForm.addEventListener('submit', (e) => {
      e.preventDefault()
      syncFilterUrl()
      loadDocs({ fromPoll: false })
    })
  }

  const resetBtn = $('#resetFiltersBtn')
  if (resetBtn) {
    resetBtn.addEventListener('click', () => {
      filterForm?.reset()
      syncFilterUrl()
      loadDocs({ fromPoll: false })
    })
  }
})

// ---------------------------------------------------------------------------
// Load / render documents
// ---------------------------------------------------------------------------
async function loadDocs(opts = {}) {
  try {
    pollInFlight = true
    const params = buildFilters()
    const url = params.toString() ? `${API.list}?${params.toString()}` : API.list
    const r = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (r.status === 401) { handleUnauthorized(); return }
    if (!r.ok) throw new Error(`Documents request failed (${r.status})`)
    const payload = await r.json()
    const list = Array.isArray(payload) ? payload : (payload.documents || [])
    renderDocs(list)
    renderSummary(Array.isArray(payload) ? null : payload.summary)
    const any = hasProcessing(list)
    const next = adjustDelay({ anyProcessing: any, fromPoll: !!opts.fromPoll })
    if (next == null) stopPolling()
    else scheduleNextPoll(next)
  } catch (err) {
    // ignore polling errors silently
  } finally {
    pollInFlight = false
  }
}

function renderDocs(list) {
  const tbody = document.querySelector('#docsTable tbody')
  if (!tbody) return
  tbody.innerHTML = ''
  list.forEach(d => {
    const tr = el('tr')
    tr.append(
      td(d.id),
      td(d.filename),
      td(d.client_name || ''),
      td(d.date_received || ''),
      td(d.completed_date || ''),
      td(d.status),
      td(d.credit_status || '—'),
      td(d.pages_requested || 0),
      td(d.payment_reference || '—'),
      td(d.partner_request_id || '—'),
      tdLink(d.csv_download, 'csv'),
      tdLink(d.xlsx_download, 'xlsx'),
      td(new Date(d.created_at).toLocaleString()),
      tdDelete(d.id),
    )
    tbody.appendChild(tr)
  })
}

function renderSummary(summary) {
  if (!summary) return
  const formatTriplet = (value) => `Day ${value?.day ?? 0} | Month ${value?.month ?? 0} | All ${value?.all ?? 0}`
  if ($('#summary_filtered_documents')) $('#summary_filtered_documents').textContent = String(summary.filtered_documents ?? 0)
  if ($('#summary_filtered_pages')) $('#summary_filtered_pages').textContent = `${summary.filtered_pages ?? 0} pages`
  if ($('#summary_booklet_successful')) $('#summary_booklet_successful').textContent = formatTriplet(summary.booklet_successful)
  if ($('#summary_booklet_pages')) $('#summary_booklet_pages').textContent = formatTriplet(summary.booklet_pages)
  if ($('#summary_booklet_student_rows')) $('#summary_booklet_student_rows').textContent = formatTriplet(summary.booklet_student_rows)
  if ($('#summary_certificate_pages')) $('#summary_certificate_pages').textContent = formatTriplet(summary.certificate_pages)
}

async function deleteDoc(id) {
  if (!confirm('Delete this document and its extracted certificate data?')) return
  try {
    const csrfToken = document.querySelector('input[name="_token"]')?.value
    if (!csrfToken) { alert('Security token missing. Please refresh the page.'); return }
    const r = await fetch(API.delete(id), {
      method: 'DELETE',
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    })
    if (r.status === 401) { handleUnauthorized(); return }
    const contentType = r.headers.get('content-type') || ''
    if (!contentType.includes('application/json')) throw new Error('Server returned non-JSON response')
    const result = await r.json()
    if (!r.ok) throw new Error(result.message || result.error || 'Delete failed')
    loadDocs({ fromPoll: false })
    loadCreditSummary()
  } catch (err) {
    alert('Delete failed: ' + (err.message || 'Unknown error'))
  }
}

// ---------------------------------------------------------------------------
// DOM helpers
// ---------------------------------------------------------------------------
function td(v) {
  const d = document.createElement('td'); d.textContent = v ?? ''; d.className = 'p-2 border-b'; return d
}

function tdLink(url, format) {
  const d = document.createElement('td'); d.className = 'p-2 border-b'
  if (url) {
    const a = document.createElement('a')
    a.href = url
    a.className = 'text-lime-700 underline'
    a.textContent = format.toUpperCase()
    a.download = ''
    d.appendChild(a)
  } else {
    d.textContent = '—'
  }
  return d
}

function tdDelete(id) {
  const d = document.createElement('td'); d.className = 'p-2 border-b'
  const btn = document.createElement('button')
  btn.textContent = 'Delete'
  btn.className = 'text-red-600 hover:underline text-xs'
  btn.onclick = () => deleteDoc(id)
  d.appendChild(btn)
  return d
}
