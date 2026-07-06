-- Единый поток: принятая заявка → активация аккаунта (задать пароль по ссылке).
ALTER TABLE club_applications
  ADD COLUMN activation_token CHAR(40) NULL,
  ADD COLUMN activated_at TIMESTAMP NULL;
