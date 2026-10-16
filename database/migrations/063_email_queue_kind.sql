-- The scheduled newsletter digest is gone: the emailer now only sends
-- event-driven mail (chat/support, one email for today's new gallery, the live
-- broadcast, the 7-day renewal reminder and win-back). Billing/dunning mail and
-- those broadcasts all land in email_queue with audience='notification', so the
-- per-member daily cap could not tell them apart - a renewal reminder would use
-- up a member's one allowance and silently drop the day's gallery email.
--
-- `kind` labels each row so the cap only applies to the two broadcasts:
--   gallery | live | chat | lifecycle
ALTER TABLE email_queue
    ADD COLUMN kind VARCHAR(30) NOT NULL DEFAULT 'notification' AFTER audience;

-- Backfill the rows already in the table: billing mail arrived through
-- enqueueTx (subject patterns are the only signal), everything else that was
-- enqueued as a broadcast notification keeps 'notification' until it ages out.
UPDATE email_queue
    SET kind = 'lifecycle'
    WHERE kind = 'notification'
      AND (subject LIKE 'Your payment%'
           OR subject LIKE 'Your subscription is past due%'
           OR subject LIKE 'Your membership has ended%'
           OR subject LIKE 'Your renewal is coming up%'
           OR subject LIKE 'We miss you%');

CREATE INDEX idx_email_queue_kind ON email_queue (kind, created_at);
