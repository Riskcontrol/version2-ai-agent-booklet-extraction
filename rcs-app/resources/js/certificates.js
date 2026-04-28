const API = {
  upload: '/api/certificates/upload',
  list: '/api/certificates',
  delete: (id) => `/api/certificates/${id}`,
}

function $(sel) { return document.querySelector(sel) }
function el(tag, attrs = {}) { const e = document.createElement(tag); Object.assign(e, attrs); return e }

// ---------------------------------------------------------------------------
// Adaptive polling
// ---------------------------------------------------------------------------
let pollTimer = null
let pollInFlight = false
let pollDelayMs = 4000
let authRedirectScheduled = false

function hasProcessing(list) {
  return Array.isArray(list) && list.some(d => String(d?.status || '').toLowerCase() === 'processing')
}

function stopPolling() {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = null
  pollDelayMs = 4000
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

// ---------------------------------------------------------------------------
// DOMContentLoaded
// ---------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
  const y = document.getElementById('year'); if (y) y.textContent = new Date().getFullYear()
  loadDocs({ fromPoll: false })

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && pollTimer) scheduleNextPoll(800)
  })

  const up = $('#uploadForm')
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
      fd.append('api_key_tier', $('#api_key_tier')?.value || 'GEMINI_API_KEY_FREE_TIER_1')
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
})

// ---------------------------------------------------------------------------
// Load / render documents
// ---------------------------------------------------------------------------
async function loadDocs(opts = {}) {
  try {
    pollInFlight = true
    const r = await fetch(API.list, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (r.status === 401) { handleUnauthorized(); return }
    if (!r.ok) throw new Error(`Documents request failed (${r.status})`)
    const list = await r.json()
    renderDocs(list)
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
      tdLink(d.csv_download, 'csv'),
      tdLink(d.xlsx_download, 'xlsx'),
      td(new Date(d.created_at).toLocaleString()),
      tdDelete(d.id),
    )
    tbody.appendChild(tr)
  })
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
