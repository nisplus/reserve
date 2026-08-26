-- Company-scoped back office accounts.
--
-- Until now every admin_users row was the office staff, seeing everything.
-- Each of the 14 companies now gets accounts that reach only their own
-- events, sessions and bookings. Existing rows default to superadmin with a
-- NULL company, which is exactly what the office account already was.
--
-- The role/company_id pairing - superadmin has no company, company must have
-- one - is enforced in AdminUserRepository, NOT by a CHECK constraint.
--
-- A CHECK was the first choice and had to be abandoned. MariaDB 11.8 rejects
--   ADD CONSTRAINT chk_admin_users_scope
--     CHECK ((role='superadmin' AND company_id IS NULL)
--         OR (role='company' AND company_id IS NOT NULL))
-- with errno 1901, "Function or expression 'company_id' cannot be used in the
-- CHECK clause", even as a statement of its own against columns that already
-- exist. MariaDB 10.4 accepts the same DDL, so the constraint passed every
-- development run and blocked the deployment instead. A guard that only holds
-- on the development machine is worse than no guard: it hides the fact that
-- something else has to do the work.
--
-- AdminUserRepository::create() and updateProfile() now refuse a mismatched
-- pair outright, which every write path goes through (admin screens and
-- bin/create_admin.php alike), and tests/test_authz.php checks it.
--
-- Two statements, not one: adding a column and referring to it from a
-- constraint in the same ALTER TABLE is resolved against the table as it
-- stands before the statement runs, which some builds reject.

ALTER TABLE admin_users
  ADD COLUMN role ENUM('superadmin','company') NOT NULL DEFAULT 'superadmin' AFTER display_name,
  ADD COLUMN company_id INT UNSIGNED NULL AFTER role;

ALTER TABLE admin_users
  ADD KEY idx_admin_users_company (company_id),
  ADD CONSTRAINT fk_admin_users_company FOREIGN KEY (company_id)
    REFERENCES companies(id) ON DELETE RESTRICT ON UPDATE CASCADE;
