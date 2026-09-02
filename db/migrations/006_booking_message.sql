-- A free-text note from the person booking to the host company.
--
-- Optional: most bookings will not carry one, and demanding a message would
-- put a blank box in the way of every reservation. TEXT rather than a sized
-- VARCHAR because there is no natural limit to "anything you want to tell
-- them"; the form caps it at 1000 characters, which is a judgement about
-- readability rather than storage.
--
-- It belongs to the booking, not to an attendee: it is addressed to the
-- company, from the party as a whole.

ALTER TABLE bookings
  ADD COLUMN message TEXT NULL AFTER name;
