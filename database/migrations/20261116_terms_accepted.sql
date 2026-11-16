-- Audit P2 #20: record Terms of Service + Privacy Policy
-- acceptance. Stamped on the pending registration when the
-- signup form's checkbox is ticked, copied to the user row
-- when the verification link creates the account. Google
-- sign-ups stamp it at account creation (the signup page
-- states acceptance next to the Google button).
ALTER TABLE pending_registrations
  ADD COLUMN terms_accepted_at DATETIME NULL DEFAULT NULL AFTER expires_at;
ALTER TABLE users
  ADD COLUMN terms_accepted_at DATETIME NULL DEFAULT NULL;
