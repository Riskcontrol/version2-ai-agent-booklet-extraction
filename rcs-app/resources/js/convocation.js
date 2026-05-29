import { PDFDocument } from 'pdf-lib'

const API = {
  upload: '/api/upload',
  list: '/api/documents',
  summary: '/api/credit-summary',
  delete: (id) => `/api/documents/${id}`
}

let totalPagesDetected = null
let countingPages = false
let availableCredits = 0
const TRACKING_UI_ENABLED = window?.rcsFeatureFlags?.trackingUiEnabled !== false

function $(sel){ return document.querySelector(sel) }
function el(tag, attrs={}){ const e=document.createElement(tag); Object.assign(e, attrs); return e }

function updateCreditPreview(totalPages, selectedPages){
  const totalNode = document.getElementById('totalPages')
  const selectedNode = document.getElementById('pagesSelected')
  const neededNode = document.getElementById('neededCredits')

  if (totalNode) totalNode.textContent = Number.isFinite(totalPages) && totalPages > 0 ? String(totalPages) : '-'
  if (selectedNode) selectedNode.textContent = Number.isFinite(selectedPages) && selectedPages > 0 ? String(selectedPages) : '-'
  if (neededNode) neededNode.textContent = Number.isFinite(selectedPages) && selectedPages > 0 ? String(selectedPages) : '-'
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

function currentSelectedPages(){
  if (!Number.isFinite(totalPagesDetected) || totalPagesDetected < 1) return null

  const sp = parseInt($('#page_start')?.value?.trim() || '0', 10)
  const ep = parseInt($('#page_end')?.value?.trim() || '0', 10)
  const start = Number.isFinite(sp) && sp > 0 ? sp : 1
  const end = Number.isFinite(ep) && ep > 0 ? ep : totalPagesDetected

  if (start < 1 || end < 1 || end < start || end > totalPagesDetected) return null
  return (end - start) + 1
}

function refreshSelectedPagesPreview(){
  const selected = currentSelectedPages()
  updateCreditPreview(totalPagesDetected, selected)
  updateUploadGate(selected)
}

function updateUploadGate(selectedPages = null){
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

  const neededCredits = Number.isFinite(selectedPages) && selectedPages > 0
    ? selectedPages
    : currentSelectedPages()

  if (!neededCredits) {
    uploadBtn.disabled = true
    setGateMessage({ text: 'Unable to compute selected pages for credit check.', tone: 'text-red-600' })
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
// Adaptive polling (auto-refresh) for documents list
// ---------------------------------------------------------------------------
let pollTimer = null
let pollInFlight = false
let pollDelayMs = 4000
let authRedirectScheduled = false
let creditSummaryTimer = null

function hasProcessing(list){
  return Array.isArray(list) && list.some(d => String(d?.status || '').toLowerCase() === 'processing')
}

function stopPolling(){
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
  pollDelayMs = 4000
}

function startCreditSummaryPolling(){
  stopCreditSummaryPolling()
  creditSummaryTimer = setInterval(() => {
    if (!document.hidden) loadCreditSummary()
  }, 30000)
}

function stopCreditSummaryPolling(){
  if (creditSummaryTimer) clearInterval(creditSummaryTimer)
  creditSummaryTimer = null
}

function scheduleNextPoll(nextDelayMs){
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = setTimeout(() => {
    // avoid overlapping requests
    if (!pollInFlight) loadDocs({ fromPoll: true })
    else scheduleNextPoll(Math.min((nextDelayMs || pollDelayMs) + 2000, 30000))
  }, nextDelayMs)
}

function adjustDelay({ anyProcessing, fromPoll }){
  // Base behavior:
  // - While processing: poll fast (4s -> 30s backoff)
  // - When complete: stop polling
  // - When tab hidden: slow down a lot
  const hidden = document.hidden === true
  if (!anyProcessing) return null

  if (!fromPoll) {
    // After a user action (upload/delete), poll quickly.
    pollDelayMs = 3000
  } else {
    // Back off gradually during long processing runs.
    pollDelayMs = Math.min(Math.round(pollDelayMs * 1.25), 30000)
  }

  if (hidden) {
    pollDelayMs = Math.max(pollDelayMs, 20000)
  }
  return pollDelayMs
}

function handleUnauthorized(){
  if (authRedirectScheduled) return
  authRedirectScheduled = true
  stopPolling()

  const msg = $('#uploadMsg')
  if (msg) {
    msg.textContent = 'Your session has expired. Redirecting to login...'
    msg.className = 'mt-3 text-sm text-red-600'
  }

  setTimeout(() => {
    window.location.href = '/login'
  }, 800)
}

function buildFilters(){
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

function syncFilterUrl(){
  const params = buildFilters()
  const next = params.toString()
  const url = next ? `${window.location.pathname}?${next}` : window.location.pathname
  window.history.replaceState({}, '', url)
}

function hydrateFiltersFromUrl(){
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

async function loadCreditSummary(){
  const msg = $('#creditSummaryMsg')
  try {
    const r = await fetch(API.summary, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    if (r.status === 401) {
      handleUnauthorized()
      return
    }
    if (!r.ok) throw new Error(`Credit summary failed (${r.status})`)
    const summary = await r.json()
    availableCredits = Number.isFinite(Number(summary.credit_balance)) ? Number(summary.credit_balance) : 0
    $('#creditBalance').textContent = String(summary.credit_balance ?? 0)
    $('#creditValue').textContent = `NGN ${(summary.credit_value_naira ?? 0).toLocaleString()}`
    $('#creditUnitPrice').textContent = `NGN ${(summary.unit_price_naira ?? 0).toLocaleString()} per page`
    $('#billingAuthority').textContent = 'Google Cloud'
    refreshSelectedPagesPreview()
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

document.addEventListener('DOMContentLoaded', () => {
  const y = document.getElementById('year'); if (y) y.textContent = new Date().getFullYear();
  hydrateFiltersFromUrl()
  loadDocs({ fromPoll: false })
  loadCreditSummary()
  startCreditSummaryPolling()

  // If user returns to the tab and there are still items processing,
  // the next poll will happen with a shorter delay.
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && pollTimer) {
      // trigger a near-immediate refresh
      scheduleNextPoll(800)
      loadCreditSummary()
    }
  })

  window.addEventListener('beforeunload', () => {
    stopPolling()
    stopCreditSummaryPolling()
  })

  const up = $('#uploadForm');
  const fileNode = $('#file')
  const startNode = $('#page_start')
  const endNode = $('#page_end')

  const initialBalance = Number($('#creditBalance')?.textContent || '0')
  availableCredits = Number.isFinite(initialBalance) ? initialBalance : 0

  if (startNode) startNode.addEventListener('input', refreshSelectedPagesPreview)
  if (endNode) endNode.addEventListener('input', refreshSelectedPagesPreview)
  if (fileNode) {
    fileNode.addEventListener('change', async () => {
      const file = fileNode.files?.[0]
      totalPagesDetected = null
      updateCreditPreview(null, null)
      updateUploadGate(null)
      if (!file) return

      countingPages = true
      const msg = $('#uploadMsg')
      if (msg) {
        msg.textContent = 'Calculating PDF pages and needed credits...'
        msg.className = 'mt-3 text-sm text-gray-600'
      }

      try {
        const detected = await detectPdfPages(file)
        totalPagesDetected = detected > 0 ? detected : null
        refreshSelectedPagesPreview()

        if (msg && totalPagesDetected) {
          msg.textContent = `Detected ${totalPagesDetected} page(s). Needed credits update automatically at 1 credit per page.`
          msg.className = 'mt-3 text-sm text-green-700'
        }
      } catch {
        if (msg) {
          msg.textContent = 'Unable to auto-detect page count from this PDF. Upload stays disabled until page count is detected.'
          msg.className = 'mt-3 text-sm text-amber-700'
        }
        updateUploadGate(null)
      } finally {
        countingPages = false
        updateUploadGate(null)
      }
    })
  }

  updateUploadGate(null)

  if (up) up.addEventListener('submit', async (e) => {
    e.preventDefault()
    const f = $('#file').files[0]
    if (!f) return

    // Prevent impossible uploads when server limit is lower than selected file size.
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
    
    // Validate page range
    const sp = parseInt($('#page_start')?.value?.trim() || '0')
    const ep = parseInt($('#page_end')?.value?.trim() || '0')
    const pageError = $('#pageValidationError')
    
    if (sp && ep && ep < sp) {
      pageError.textContent = 'End page must be greater than or equal to start page'
      pageError.classList.remove('hidden')
      return
    }
    if (totalPagesDetected && ep && ep > totalPagesDetected) {
      pageError.textContent = `End page cannot exceed detected total pages (${totalPagesDetected})`
      pageError.classList.remove('hidden')
      return
    } else {
      pageError.classList.add('hidden')
    }

    if (countingPages) {
      if (msg) {
        msg.textContent = 'Please wait for page counting to finish.'
        msg.className = 'mt-3 text-sm text-amber-700'
      }
      return
    }

    refreshSelectedPagesPreview()
    if ($('#uploadBtn')?.disabled) return
    
    const fd = new FormData()
    fd.append('file', f)
    if ($('#session')?.value) fd.append('session', $('#session').value)
    if (sp > 0) fd.append('start_page', sp)
    if (ep > 0) fd.append('end_page', ep)

    // Get CSRF token
    const csrfToken = document.querySelector('input[name="_token"]')?.value
    
    if (!csrfToken) {
      if (msg) {
        msg.textContent = 'Security token missing. Please refresh the page.'
        msg.className = 'mt-3 text-sm text-red-600'
      }
      return
    }
    
    // Show progress bar
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
      // Simulate progress during upload
      let progress = 0
      progressInterval = setInterval(() => {
        progress += 5
        if (progress <= 90) {
          progressBar.style.width = progress + '%'
        }
      }, 200)

      const r = await fetch(API.upload, { 
        method:'POST', 
        headers: {
          'X-CSRF-TOKEN': csrfToken,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: fd 
      })

      if (r.status === 413) {
        throw new Error('File is too large for server upload limit. Please reduce PDF size or increase server limits (Nginx client_max_body_size / PHP upload_max_filesize, post_max_size).')
      }
      if (r.status === 401) {
        handleUnauthorized()
        return
      }
      
      if (progressInterval) clearInterval(progressInterval)
      progressBar.style.width = '100%'
      
      // Check if response is JSON
      const contentType = r.headers.get('content-type')
      if (!contentType || !contentType.includes('application/json')) {
        throw new Error('Server returned non-JSON response. Check if you are logged in.')
      }
      
      const result = await r.json()
      
      if (r.ok) {
        progressText.textContent = 'Upload complete! Processing...'
        if (msg) {
          msg.textContent = 'Queued for processing. Refresh documents shortly.'
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
    } catch(err){
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

async function loadDocs(opts = {}){
  try {
    pollInFlight = true
    const params = buildFilters()
    const url = params.toString() ? `${API.list}?${params.toString()}` : API.list
    const r = await fetch(url, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    if (r.status === 401) {
      handleUnauthorized()
      return
    }
    if (!r.ok) {
      throw new Error(`Documents request failed (${r.status})`)
    }
    const payload = await r.json()
    const list = Array.isArray(payload) ? payload : (payload.documents || [])
    renderDocs(list)
    renderSummary(Array.isArray(payload) ? null : payload.summary)

    const any = hasProcessing(list)
    const next = adjustDelay({ anyProcessing: any, fromPoll: !!opts.fromPoll })
    if (next == null) {
      stopPolling()
    } else {
      scheduleNextPoll(next)
    }
  } catch(err){
    // ignore
  } finally {
    pollInFlight = false
  }
}

function renderDocs(list){
  const tbody = document.querySelector('#docsTable tbody')
  if (!tbody) return
  tbody.innerHTML = ''
  list.forEach(d => {
    const tr = el('tr')
    tr.append(
      td(d.id),
      td(d.filename),
      td(d.session||''),
      td(d.status),
      td(d.credit_status || '—'),
      td(d.pages_requested || 0),
      td(d.payment_reference || '—'),
      td(d.partner_request_id || '—'),
      tdLink(d.csv_download, 'csv'),
      tdLink(d.xlsx_download, 'xlsx'),
      td(new Date(d.created_at).toLocaleString()),
      tdActions(d)
    )
    tbody.appendChild(tr)
  })
}

function renderSummary(summary){
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
  if (!confirm('Delete this document and its extracted data?')) return
  try {
    const csrfToken = document.querySelector('input[name="_token"]')?.value
    if (!csrfToken) {
      alert('Security token missing. Please refresh the page.')
      return
    }

    const r = await fetch(API.delete(id), { 
      method: 'DELETE',
      credentials: 'same-origin',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    if (r.status === 401) {
      handleUnauthorized()
      return
    }

    const contentType = r.headers.get('content-type') || ''
    if (!contentType.includes('application/json')) {
      throw new Error('Server returned non-JSON response')
    }
    const result = await r.json()
    if (!r.ok) {
      throw new Error(result.message || result.error || 'Delete failed')
    }
    loadDocs({ fromPoll: false })
    loadCreditSummary()
  } catch(err) {
    alert('Delete failed: ' + (err.message || 'Unknown error'))
  }
}

function renderResults(rows){
  const tbody = document.querySelector('#resultsTable tbody')
  if (!tbody) return
  tbody.innerHTML = ''
  rows.forEach(r => {
    const tr = el('tr')
    tr.append(
      td(r.surname),
      td(r.first_name),
      td(r.other_name||''),
      td(r.course_studied||''),
      td(r.faculty||''),
      td(r.grade||''),
      td(r.qualification_obtained||''),
      td(r.session||'')
    )
    tbody.appendChild(tr)
  })
}

function td(v){ const d=document.createElement('td'); d.textContent=v??''; d.className='p-2 border-b'; return d }
function tdLink(url, format){
  const d=document.createElement('td'); d.className='p-2 border-b'
  if(url){
    const a=document.createElement('a');
    a.href=url;
    a.className='text-lime-700 underline';
    a.textContent = 'Download';
    a.setAttribute('download', ''); // hint browser to download
    d.appendChild(a)
  }
  return d
}
function tdDelete(id){
  const d=document.createElement('td'); d.className='p-2 border-b'
  const btn=document.createElement('button');
  btn.textContent='Delete';
  btn.className='text-red-600 hover:text-red-800 underline text-sm';
  btn.onclick = () => deleteDoc(id);
  d.appendChild(btn);
  return d;
}

function tdActions(doc){
  const d = document.createElement('td')
  d.className = 'p-2 border-b'

  const wrap = document.createElement('div')
  wrap.className = 'flex items-center gap-3'

  if (TRACKING_UI_ENABLED) {
    const link = document.createElement('a')
    link.className = 'text-lime-700 hover:text-lime-800 underline text-sm'
    link.textContent = 'Track'
    link.href = `/tracking/${doc.id}`
    wrap.appendChild(link)
  }

  const btn = document.createElement('button')
  btn.textContent = 'Delete'
  btn.className = 'text-red-600 hover:text-red-800 underline text-sm'
  btn.onclick = () => deleteDoc(doc.id)
  wrap.appendChild(btn)

  d.appendChild(wrap)
  return d
}

