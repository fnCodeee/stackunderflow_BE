-- Theme preference per account (2026-11-18): the theme mode
-- (light | system | dark) used to live only in localStorage, which
-- is per-origin, so the choice appeared lost whenever the app was
-- opened from another origin, browser, or device. Stored on the
-- profile; the frontend syncs it at login and on every toggle.
ALTER TABLE profiles
    ADD COLUMN theme_mode VARCHAR(10) NULL AFTER photo_profile_public_id;
