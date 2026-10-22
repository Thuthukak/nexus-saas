// Pure helpers for the toast poller.

/**
 * Which notifications should pop up as toasts now: unread, created after the page loaded,
 * and not toasted before.
 */
export function newToasts(notifications, seenIds, sinceMs) {
  return (notifications || []).filter(
    (n) => !n.read_at && !seenIds.has(n.id) && Date.parse(n.created_at) > sinceMs,
  )
}
