-- Events that need no booking.
--
-- Some listings are there to be announced, not reserved: a drop-in exhibit,
-- or something whose registration lives on the host company's own site. Such
-- an event shows no sessions and points elsewhere instead.
--
-- The column is phrased positively (booking_required, default 1) rather than
-- as "no_booking_required", so the ordinary case reads as
-- `if ($event['booking_required'])` instead of a double negative, and so the
-- default matches what every existing row already means. The admin form shows
-- it as a 予約不要 checkbox and inverts once, in the controller.
--
-- external_url is generous at 500 characters because campaign URLs carry
-- tracking parameters. Only http/https are accepted, validated in the
-- application: a javascript: URL reaching an href would be stored XSS.

ALTER TABLE events
  ADD COLUMN booking_required TINYINT(1) NOT NULL DEFAULT 1 AFTER venue,
  ADD COLUMN external_url VARCHAR(500) NULL AFTER booking_required;
