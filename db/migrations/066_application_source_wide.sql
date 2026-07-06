-- «Как узнали про клуб» стало мультивыбором — расширяем колонку под несколько вариантов.
ALTER TABLE club_applications MODIFY source VARCHAR(500) NOT NULL;
