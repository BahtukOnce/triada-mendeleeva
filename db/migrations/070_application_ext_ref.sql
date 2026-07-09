-- Метка внешнего источника заявки (напр. «gform:04.07.2026 9:45:39») — дедуп импорта
-- из старой Google-формы, чтобы повторный запуск крона не плодил дубли.
ALTER TABLE club_applications ADD COLUMN ext_ref VARCHAR(120) NULL, ADD KEY idx_app_ext (ext_ref);
