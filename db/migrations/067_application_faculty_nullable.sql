-- Факультет теперь спрашивается только у студентов/выпускников; у сотрудника/гостя его
-- нет — колонка должна допускать NULL (иначе INSERT падает на NOT NULL).
ALTER TABLE club_applications MODIFY faculty VARCHAR(40) NULL;
