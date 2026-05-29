function $(sel) { return document.querySelector(sel) }

function fmtDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString()
}

function titleCase(value) {
  return String(value || '')
    .replaceAll('_', ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function setError(message) {
  const node = $('#trackingError')
  if (!node) return
  if (!message) {
    node.textContent = ''
    node.classList.add('hidden')
    return
  }
  node.textContent = message
  node.classList.remove('hidden')
}

function renderTimeline(data) {
  const node = $('#trackingTimeline')
  if (!node) return

  const events = [
    ['Document created', data.created_at],
    ['Partner authorization phase', data.partner_tracking?.created_at],
    ['Partner finalized', data.partner_tracking?.finalized_at],
    ['Authorization expires', data.partner_tracking?.expires_at],
  ].filter(([, value]) => !!value)

  if (data.failed_reason) {
    events.unshift(['Failure reason', data.failed_reason])
  }

  if (events.length === 0) {
    node.innerHTML = '<div class="text-sm text-gray-500">No tracking events available yet.</div>'
    return
  }

  node.innerHTML = events.map(([label, value]) => `
    <div class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
      <div class="font-medium text-gray-900">${label}</div>
      <div class="text-gray-600">${label === 'Failure reason' ? value : fmtDate(value)}</div>
    </div>
  `).join('')
}

function renderDownloads(data) {
  const node = $('#trackingDownloads')
  if (!node) return
  const links = []

  if (data.csv_download) {
    links.push(`<a href="${data.csv_download}" class="px-4 py-2 rounded-lg bg-lime-500 text-[#0a2912] font-medium" download>Download CSV</a>`)
  }
  if (data.xlsx_download) {
    links.push(`<a href="${data.xlsx_download}" class="px-4 py-2 rounded-lg bg-[#0a2912] text-white font-medium" download>Download XLSX</a>`)
  }

  node.innerHTML = links.length > 0
    ? links.join('')
    : '<div class="text-sm text-gray-500">Downloads will appear when extraction completes.</div>'
}

function renderTracking(data) {
  $('#trackingFilename') && ($('#trackingFilename').textContent = data.filename || 'Document')
  $('#trackingMeta') && ($('#trackingMeta').textContent = `Document ID ${data.document_id} | Partner Request ${data.partner_request_id || 'N/A'}`)
  $('#trackingPhase') && ($('#trackingPhase').textContent = titleCase(data.phase || 'processing'))
  $('#trackingStatus') && ($('#trackingStatus').textContent = titleCase(data.status || 'processing'))
  $('#trackingPages') && ($('#trackingPages').textContent = `${data.pages_processed || 0} / ${data.pages_requested || 0}`)
  $('#trackingCredit') && ($('#trackingCredit').textContent = titleCase(data.credit_status || 'none'))

  const percent = Number.isFinite(Number(data.progress_percent)) ? Number(data.progress_percent) : 0
  if ($('#trackingProgressBar')) $('#trackingProgressBar').style.width = `${Math.max(0, Math.min(100, percent))}%`
  if ($('#trackingProgressText')) {
    $('#trackingProgressText').textContent = `${percent}% complete | Phase: ${titleCase(data.phase || 'processing')}`
  }

  setError(data.tracking_error || data.failed_reason || '')
  renderTimeline(data)
  renderDownloads(data)
}

async function loadTracking() {
  const endpoint = window.trackingConfig?.endpoint
  if (!endpoint) return false

  const response = await fetch(endpoint, {
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  })

  if (response.status === 401) {
    window.location.href = '/login'
    return false
  }

  const data = await response.json()
  if (!response.ok) {
    throw new Error(data?.error || data?.message || 'Unable to load tracking information.')
  }

  renderTracking(data)
  return ['complete', 'failed'].includes(String(data.status || '').toLowerCase()) === false
}

document.addEventListener('DOMContentLoaded', async () => {
  try {
    let shouldPoll = await loadTracking()
    if (!shouldPoll) return

    const timer = setInterval(async () => {
      try {
        shouldPoll = await loadTracking()
        if (!shouldPoll) clearInterval(timer)
      } catch (error) {
        setError(error?.message || 'Unable to refresh tracking information.')
      }
    }, 5000)
  } catch (error) {
    setError(error?.message || 'Unable to load tracking information.')
  }
})
