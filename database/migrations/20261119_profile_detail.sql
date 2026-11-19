-- Profile Detail: long-form markdown text shown at the top of the
-- profile Overview (edited from Settings -> Profile).
ALTER TABLE profiles ADD COLUMN profile_detail TEXT NULL AFTER bio;
