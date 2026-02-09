const API = {
  upload: '/api/upload',
  list: '/api/documents',
  delete: (id) => `/api/documents/${id}`
}

function $(sel){ return document.querySelector(sel) }
function el(tag, attrs={}){ const e=document.createElement(tag); Object.assign(e, attrs); return e }

document.addEventListener('DOMContentLoaded', () => {
  const y = document.getElementById('year'); if (y) y.textContent = new Date().getFullYear();
  loadDocs()

  const up = $('#uploadForm');
  if (up) up.addEventListener('submit', async (e) => {
    e.preventDefault()
    const f = $('#file').files[0]
    if (!f) return
    
    // Validate page range
    const sp = parseInt($('#page_start')?.value?.trim() || '0')
    const ep = parseInt($('#page_end')?.value?.trim() || '0')
    const pageError = $('#pageValidationError')
    
    if (sp && ep && ep < sp) {
      pageError.textContent = 'End page must be greater than or equal to start page'
      pageError.classList.remove('hidden')
      return
    } else {
      pageError.classList.add('hidden')
    }
    
    const fd = new FormData()
    fd.append('file', f)
    if ($('#session')?.value) fd.append('session', $('#session').value)
    if (sp > 0) fd.append('start_page', sp)
    if (ep > 0) fd.append('end_page', ep)
    
    // Add API key tier selection
    const apiTier = $('#api_key_tier')?.value || 'GEMINI_API_KEY_FREE_TIER_1'
    fd.append('api_key_tier', apiTier)

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
    const msg = $('#uploadMsg')
    
    uploadBtn.disabled = true
    uploadProgress.classList.remove('hidden')
    progressBar.style.width = '0%'
    progressText.textContent = 'Uploading PDF...'
    if (msg) msg.textContent = ''

    try {
      // Simulate progress during upload
      let progress = 0
      const progressInterval = setInterval(() => {
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
      
      clearInterval(progressInterval)
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
          loadDocs()
          up.reset()
          uploadBtn.disabled = false
        }, 2000)
      } else {
        throw new Error(result.message || result.error || 'Upload failed')
      }
    } catch(err){
      uploadProgress.classList.add('hidden')
      uploadBtn.disabled = false
      if (msg) {
        msg.textContent = 'Upload failed: ' + (err.message || 'Unknown error')
        msg.className = 'mt-3 text-sm text-red-600'
      }
    }
  })
})

async function loadDocs(){
  try {
    const r = await fetch(API.list, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    const list = await r.json()
    renderDocs(list)
  } catch(err){
    // ignore
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
      tdLink(d.csv_download, 'csv'),
      tdLink(d.xlsx_download, 'xlsx'),
      td(new Date(d.created_at).toLocaleString()),
      tdDelete(d.id)
    )
    tbody.appendChild(tr)
  })
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

    const contentType = r.headers.get('content-type') || ''
    if (!contentType.includes('application/json')) {
      throw new Error('Server returned non-JSON response')
    }
    const result = await r.json()
    if (!r.ok) {
      throw new Error(result.message || result.error || 'Delete failed')
    }
    loadDocs()
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

