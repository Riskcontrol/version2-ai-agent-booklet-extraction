const API = {
  list: '/api/documents',
  delete: (id) => `/api/documents/${id}`,
}

let pollTimer = null
let pollInFlight = false
let pollDelayMs = 4000
let authRedirectScheduled = false
const TRACKING_UI_ENABLED = window?.rcsFeatureFlags?.trackingUiEnabled !== false

function $(sel){ return document.querySelector(sel) }
function el(tag, attrs={}){ const e=document.createElement(tag); Object.assign(e, attrs); return e }

function td(v){ const d=document.createElement('td'); d.textContent=v??''; d.className='p-2 border-b'; return d }

function tdLink(url){
  const d=document.createElement('td'); d.className='p-2 border-b'
  if(url){
    const a=document.createElement('a')
    a.href=url
    a.className='text-lime-700 underline'
    a.textContent='Download'
    a.setAttribute('download','')
    d.appendChild(a)
  }
  return d
}

function tdActions(doc){
  const d=document.createElement('td'); d.className='p-2 border-b'
  const wrap=document.createElement('div')
  wrap.className='flex flex-col gap-1'
  if(doc?.id && TRACKING_UI_ENABLED){
    const a=document.createElement('a')
    a.href=`/tracking/${doc.id}`
    a.className='text-[#0a2912] underline text-sm'
    a.textContent='Track'
    wrap.appendChild(a)
  }
  const btn=document.createElement('button')
  btn.textContent='Delete'
  btn.className='text-red-600 hover:text-red-800 underline text-sm text-left'
  btn.onclick = () => deleteDoc(doc.id)
  wrap.appendChild(btn)
  d.appendChild(wrap)
  return d
}

function hasProcessing(list){
  return Array.isArray(list) && list.some(d => String(d?.status || '').toLowerCase() === 'processing')
}

function stopPolling(){
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
  pollDelayMs = 4000
}

function scheduleNextPoll(nextDelayMs){
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = setTimeout(() => {
    if (!pollInFlight) loadDocs({ fromPoll: true })
    else scheduleNextPoll(Math.min((nextDelayMs || pollDelayMs) + 2000, 30000))
  }, nextDelayMs)
}

function adjustDelay({ anyProcessing, fromPoll }){
  const hidden = document.hidden === true
  if (!anyProcessing) return null

  if (!fromPoll) {
    pollDelayMs = 3000
  } else {
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
  const msg = $('#logsMsg')
  if (msg) {
    msg.textContent = 'Your session has expired. Redirecting to login...'
    msg.className = 'mt-3 text-sm text-red-600'
  }
  setTimeout(() => { window.location.href = '/login' }, 800)
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

function renderDocs(list){
  const tbody = document.querySelector('#docsTable tbody')
  if (!tbody) return
  tbody.innerHTML = ''
  list.forEach(d => {
    const tr = el('tr')
    tr.append(
      td(d.id),
      td(d.filename),
      td(d.session || ''),
      td(d.status),
      td(d.credit_status || '—'),
      td(d.pages_requested || 0),
      td(d.payment_reference || '—'),
      td(d.partner_request_id || '—'),
      tdLink(d.csv_download),
      tdLink(d.xlsx_download),
      td(d.extraction_type || '—'),
      td(new Date(d.created_at).toLocaleString()),
      tdActions(d)
    )
    tbody.appendChild(tr)
  })
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
      td(r.other_name || ''),
      td(r.course_studied || ''),
      td(r.faculty || ''),
      td(r.grade || ''),
      td(r.qualification_obtained || ''),
      td(r.session || '')
    )
    tbody.appendChild(tr)
  })
}

async function loadDocs(opts = {}){
  const msg = $('#logsMsg')
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
    renderResults(Array.isArray(payload) ? [] : (payload.results || []))

    const rowCount = list.length
    if (msg) {
      msg.textContent = `Loaded ${rowCount} record(s).`
      msg.className = 'text-sm text-green-700'
    }

    const any = hasProcessing(list)
    const next = adjustDelay({ anyProcessing: any, fromPoll: !!opts.fromPoll })
    if (next == null) {
      stopPolling()
    } else {
      scheduleNextPoll(next)
    }
  } catch(err){
    if (msg) {
      msg.textContent = err?.message || 'Failed to load logs.'
      msg.className = 'text-sm text-red-600'
    }
  } finally {
    pollInFlight = false
  }
}

async function deleteDoc(id){
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
  } catch(err){
    alert('Delete failed: ' + (err?.message || 'Unknown error'))
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const y = document.getElementById('year')
  if (y) y.textContent = new Date().getFullYear()

  hydrateFiltersFromUrl()
  loadDocs({ fromPoll: false })

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && pollTimer) {
      scheduleNextPoll(800)
    }
  })

  window.addEventListener('beforeunload', () => {
    stopPolling()
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
