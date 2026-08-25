-- Company-scoped back office accounts.
--
-- Until now every admin_users row was the office staff, seeing everything.
-- Each of the 14 companies now gets accounts that reach only their own
-- events, sessions and bookings.
--
-- role + company_id are kept consistent by a CHECK rather than by convention:
-- a 'company' row without a company_id would be an account with no scope at
-- all, which the authorisation code would have to guess about. Existing rows
-- default to superadmin with a NULL company, which is exactly what the office
-- account already was.
--
-- Split into three statements on purpose. Adding a column and a CHECK that
-- references it in ONE ALTER TABLE is rejected by some MariaDB builds with
-- "Function or expression 'company_id' cannot be used in the CHECK clause"
-- (errno 1901): the expression is resolved against the table as it stands
-- before the statement runs, so the column is not there yet. MariaDB 10.4
-- happens to accept it, which is exactly why the combined form survived
-- development and failed on deployment. The columns are therefore committed
-- first, and everything that refers to them follows.

ALTER TABLE admin_users
  ADD COLUMN role ENUM('superadmin','company') NOT NULL DEFAULT 'superadmin' AFTER display_name,
  ADD COLUMN company_id INT UNSIGNED NULL AFTER role;

ALTER TABLE admin_users
  ADD KEY idx_admin_users_company (company_id),
  ADD CONSTRAINT fk_admin_users_company FOREIGN KEY (company_id)
    REFERENCES companies(id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE admin_users
  ADD CONSTRAINT chk_admin_users_scope CHECK (
    (role = 'superadmin' AND company_id IS NULL)
    OR (role = 'company' AND company_id IS NOT NULL)
  );
