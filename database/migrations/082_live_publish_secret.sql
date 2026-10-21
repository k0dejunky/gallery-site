-- 082 A separate secret for publishing to a live stream.
--
-- The stream key was BOTH the HLS playback path and the RTMP publish
-- credential, and it was handed to every paying member: /live/state
-- returned it, and the MediaMTX auth webhook accepted a publish whose
-- only credential was that key. A member could therefore push their own
-- video into the operator's path - and the recording is imported into a
-- paid gallery and auto-posted to social media.
--
-- Publishing now needs a second secret that only the operator app ever
-- sees. The key stays the playback path (it has to be, or the HLS URL
-- would not match the MediaMTX path), but it is no longer enough to
-- publish.
ALTER TABLE live_sessions
    ADD COLUMN publish_secret CHAR(64) NULL AFTER stream_key;
