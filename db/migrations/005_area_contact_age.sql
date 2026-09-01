-- Company areas, a day-of contact number, and per-attendee ages.
--
-- companies.area groups the venues for the public filter. ENUM with English
-- values rather than the Japanese labels: the value goes into a shareable
-- query string (/?area=east), where a percent-encoded 東エリア would be
-- unreadable and easy to mangle when pasted. Labels live in App\Domain\Area.
-- NULL means unassigned, which is what every existing company is until the
-- office says otherwise - filtering by area simply will not list them.
--
-- bookings.phone is one number per booking: "somebody we can reach on the
-- day", not a field per person.
--
-- booking_attendees.age is per person, because that is the level an age
-- restriction is checked at. NULL-able for the same reason the name may be
-- missing: CLI callers and older rows have no age to give.
--
-- No CHECK constraints; MariaDB 11.8 rejects the ones this project tried
-- (see 002_admin_roles.sql). Ranges are enforced in Validator and, for the
-- ones that matter, again inside the booking transaction.

ALTER TABLE companies
  ADD COLUMN area ENUM('east','south','north','main') NULL AFTER name_kana,
  ADD KEY idx_companies_area (area, sort_order, id);

ALTER TABLE bookings
  ADD COLUMN phone VARCHAR(30) NULL AFTER email;

ALTER TABLE booking_attendees
  ADD COLUMN age SMALLINT UNSIGNED NULL AFTER name;
