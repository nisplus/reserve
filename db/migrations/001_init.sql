-- Initial schema.
--
-- Everything is utf8mb4 / utf8mb4_unicode_ci, spelled out on every table.
-- The server this develops against has character_set_server unset (latin1), so
-- anything that does not say utf8mb4 would mangle Japanese text.
--
-- utf8mb4_unicode_ci rather than utf8mb4_general_ci: general_ci conflates
-- characters that Japanese users would consider distinct. The MySQL 8
-- collations (utf8mb4_0900_*, utf8mb4_ja_*) do not exist on MariaDB 10.4.
--
-- Times are DATETIME holding JST wall-clock, never TIMESTAMP: TIMESTAMP is
-- implicitly converted using the session time zone and tops out in 2038.

CREATE TABLE companies (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(120) NOT NULL,
  name_kana    VARCHAR(120) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_companies_name (name),
  KEY idx_companies_order (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  company_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(200) NOT NULL,
  description  TEXT NULL,
  venue        VARCHAR(200) NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_events_company FOREIGN KEY (company_id)
    REFERENCES companies(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  KEY idx_events_company (company_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One occurrence of an event. Named event_sessions rather than sessions to
-- keep it distinct from PHP sessions in the code.
--
-- confirmed_seats and waitlist_counter are denormalised on purpose: they are
-- the row the booking transaction takes an exclusive lock on. Deriving the
-- seat count with SUM(party_size) would not work, because under READ COMMITTED
-- there are no gap locks and rows that do not exist yet cannot be locked - two
-- concurrent bookings would both see the last seat and both take it.
CREATE TABLE event_sessions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id         INT UNSIGNED NOT NULL,
  starts_at        DATETIME NOT NULL,
  ends_at          DATETIME NOT NULL,
  capacity         SMALLINT UNSIGNED NOT NULL,
  confirmed_seats  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  waitlist_counter INT UNSIGNED NOT NULL DEFAULT 0,
  status           ENUM('open','closed') NOT NULL DEFAULT 'open',
  session_date     DATE GENERATED ALWAYS AS (DATE(starts_at)) PERSISTENT,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sessions_event FOREIGN KEY (event_id)
    REFERENCES events(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_sessions_range CHECK (ends_at > starts_at),
  -- Last line of defence against overselling if the application logic is wrong.
  -- Note the consequence: lowering capacity below the seats already taken fails
  -- here, so the admin form validates that first and explains it.
  CONSTRAINT chk_sessions_seats CHECK (confirmed_seats <= capacity),
  UNIQUE KEY uq_sessions_event_start (event_id, starts_at),
  KEY idx_sessions_event_start (event_id, starts_at),
  KEY idx_sessions_date (session_date, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per e-mail address. This exists so the booking transaction has a
-- single parent row to lock per applicant. An overlapping time range is a range
-- comparison, which no UNIQUE index can express, and row locks on bookings
-- cannot protect rows that do not exist yet - so concurrent bookings by the
-- same person are serialised through this row instead.
CREATE TABLE applicants (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_applicants_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference_code    CHAR(12) NOT NULL,
  session_id        INT UNSIGNED NOT NULL,
  applicant_id      INT UNSIGNED NOT NULL,
  email             VARCHAR(255) NOT NULL,
  name              VARCHAR(100) NOT NULL,
  party_size        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status            ENUM('confirmed','waitlisted','cancelled') NOT NULL,
  waitlist_seq      INT UNSIGNED NULL,
  cancel_token_hash CHAR(64) NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  confirmed_at      DATETIME NULL,
  cancelled_at      DATETIME NULL,

  -- MariaDB has no partial or filtered unique index, so "unique per
  -- (session, applicant) unless cancelled" is expressed as a generated column
  -- that goes NULL once cancelled. A UNIQUE index permits any number of NULLs,
  -- which is exactly what lets someone re-apply after cancelling.
  active_key VARCHAR(300) GENERATED ALWAYS AS (
    CASE WHEN status = 'cancelled' THEN NULL
         ELSE CONCAT(session_id, ':', applicant_id) END
  ) PERSISTENT,

  CONSTRAINT fk_bookings_session   FOREIGN KEY (session_id)   REFERENCES event_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_applicant FOREIGN KEY (applicant_id) REFERENCES applicants(id)     ON DELETE RESTRICT,
  CONSTRAINT chk_bookings_party CHECK (party_size BETWEEN 1 AND 20),
  UNIQUE KEY uq_bookings_token  (cancel_token_hash),
  UNIQUE KEY uq_bookings_ref    (reference_code),
  UNIQUE KEY uq_bookings_active (active_key),
  KEY idx_bookings_session_status   (session_id, status),
  KEY idx_bookings_applicant_status (applicant_id, status),
  KEY idx_bookings_email (email),
  KEY idx_bookings_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail for status changes. Answers "who cancelled this and when",
-- which comes up constantly once the system is in real use.
CREATE TABLE booking_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  booking_id  BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status   VARCHAR(20) NOT NULL,
  actor       VARCHAR(60) NOT NULL,
  note        VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bevents_booking FOREIGN KEY (booking_id)
    REFERENCES bookings(id) ON DELETE CASCADE,
  KEY idx_bevents_booking (booking_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(60) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  display_name    VARCHAR(100) NOT NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  last_login_at   DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactional outbox. Mail is queued inside the booking transaction so a
-- rolled-back booking can never leave a "you are confirmed" e-mail behind.
CREATE TABLE mail_queue (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  to_email   VARCHAR(255) NOT NULL,
  to_name    VARCHAR(100) NULL,
  subject    VARCHAR(255) NOT NULL,
  body       MEDIUMTEXT NOT NULL,
  status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  booking_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at    DATETIME NULL,
  KEY idx_mail_status (status, id),
  KEY idx_mail_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
