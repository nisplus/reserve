-- A per-event cap on how many people one application may bring, and the
-- names of everyone in a party.
--
-- max_party_size is on events, not event_sessions: "how many per application"
-- is a policy of the event, and sessions are created in bulk (the admin
-- generator makes 5-10 at a time), so per-session values would have to be
-- typed over and over for no gain. Default 20 keeps every existing event
-- behaving exactly as it did - that was the hard-coded limit - and
-- chk_bookings_party in 001_init.sql still bounds the column at 20 whatever
-- an event says.
--
-- booking_attendees holds one row per person, attendee_no 1..party_size, with
-- 1 being the applicant themselves (copied from bookings.name so an attendee
-- list is a single query rather than "the applicant plus these others").
--
-- Named attendee_no rather than position: POSITION is a function name in
-- MariaDB and would need quoting everywhere it appeared.
--
-- No CHECK constraints here. MariaDB 11.8 rejects the ones this project
-- tried to add (see 002_admin_roles.sql), so ranges are enforced in the
-- application - Validator for the form, BookingService inside the booking
-- transaction.

ALTER TABLE events
  ADD COLUMN max_party_size TINYINT UNSIGNED NOT NULL DEFAULT 20 AFTER external_url;

CREATE TABLE booking_attendees (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id  BIGINT UNSIGNED NOT NULL,
  attendee_no TINYINT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_attendees_booking FOREIGN KEY (booking_id)
    REFERENCES bookings(id) ON DELETE CASCADE,
  UNIQUE KEY uq_attendees_slot (booking_id, attendee_no),
  KEY idx_attendees_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
