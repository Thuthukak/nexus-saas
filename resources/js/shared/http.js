function xsrf() {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
  return m ? decodeURIComponent(m[1]) : ''
}

/** Same-origin JSON/FormData request with Laravel's XSRF header. Never throws for HTTP errors. */
export async function send(method, url, body = null) {
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData
  try {
    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-XSRF-TOKEN': xsrf(),
        ...(body !== null && !isForm ? { 'Content-Type': 'application/json' } : {}),
      },
      body: body === null ? undefined : isForm ? body : JSON.stringify(body),
    })
    let data = null
    try { data = await res.json() } catch { /* empty or non-JSON */ }
    return { ok: res.ok, status: res.status, data }
  } catch {
    return { ok: false, status: 0, data: null }
  }
}
