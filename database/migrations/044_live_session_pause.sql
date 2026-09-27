-- Live session pause: the operator can pause the broadcast without ending it
-- (steps away, viewers see 'the model will be back in a moment'). NULL = not
-- paused; a timestamp means the session is currently paused.
ALTER TABLE live_sessions ADD COLUMN paused_at DATETIME NULL AFTER status;