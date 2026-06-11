-- Триада Менделеева: начальная схема

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nickname VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('player','judge','admin','owner') NOT NULL DEFAULT 'player',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nickname VARCHAR(60) NOT NULL UNIQUE,
    user_id INT NULL UNIQUE,
    avatar VARCHAR(255) NULL,
    real_name VARCHAR(150) NULL,
    tg VARCHAR(100) NULL,
    vk VARCHAR(150) NULL,
    birth_date DATE NULL,
    faculty VARCHAR(100) NULL,
    study_group VARCHAR(50) NULL,
    status VARCHAR(100) NULL,
    joined_at DATE NULL,
    banned_at TIMESTAMP NULL,
    ban_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_players_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS link_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    player_id INT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at TIMESTAMP NULL,
    decided_by INT NULL,
    CONSTRAINT fk_lr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_lr_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    title VARCHAR(100) NOT NULL,
    location VARCHAR(200) NULL,
    status ENUM('draft','reg_open','reg_closed','live','finished') NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS day_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_id INT NOT NULL,
    player_id INT NOT NULL,
    time_from TIME NULL,
    time_to TIME NULL,
    comment VARCHAR(300) NULL,
    source ENUM('site','telegram') NOT NULL DEFAULT 'site',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cancelled_at TIMESTAMP NULL,
    UNIQUE KEY uq_day_player (day_id, player_id),
    CONSTRAINT fk_dr_day FOREIGN KEY (day_id) REFERENCES game_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_dr_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tournaments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    location VARCHAR(200) NULL,
    description TEXT NULL,
    status ENUM('draft','announced','reg_open','live','finished') NOT NULL DEFAULT 'draft',
    tables_count TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tournament_regs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tournament_id INT NOT NULL,
    player_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tournament_player (tournament_id, player_id),
    CONSTRAINT fk_tr_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_tr_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS games (
    id INT AUTO_INCREMENT PRIMARY KEY,
    context ENUM('day','tournament') NOT NULL DEFAULT 'day',
    day_id INT NULL,
    tournament_id INT NULL,
    table_no TINYINT NOT NULL DEFAULT 1,
    game_no SMALLINT NOT NULL DEFAULT 1,
    judge_player_id INT NULL,
    winner ENUM('red','black','draw') NULL,
    first_killed_seat TINYINT NULL,
    bm_seat1 TINYINT NULL,
    bm_seat2 TINYINT NULL,
    bm_seat3 TINYINT NULL,
    comment TEXT NULL,
    status ENUM('draft','live','finished') NOT NULL DEFAULT 'draft',
    finished_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_g_day FOREIGN KEY (day_id) REFERENCES game_days(id) ON DELETE CASCADE,
    CONSTRAINT fk_g_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    CONSTRAINT fk_g_judge FOREIGN KEY (judge_player_id) REFERENCES players(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS game_seats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    game_id INT NOT NULL,
    seat TINYINT NOT NULL,
    player_id INT NOT NULL,
    role ENUM('civ','maf','sheriff','don') NOT NULL DEFAULT 'civ',
    fouls TINYINT NOT NULL DEFAULT 0,
    tech_fouls TINYINT NOT NULL DEFAULT 0,
    plus DECIMAL(4,1) NOT NULL DEFAULT 0,
    minus DECIMAL(4,1) NOT NULL DEFAULT 0,
    protocols TEXT NULL,
    opinion TEXT NULL,
    out_order TINYINT NULL,
    out_type ENUM('voted','shot','removed') NULL,
    UNIQUE KEY uq_game_seat (game_id, seat),
    CONSTRAINT fk_gs_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
    CONSTRAINT fk_gs_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    is_main TINYINT NOT NULL DEFAULT 0,
    is_active TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rating_days (
    rating_id INT NOT NULL,
    day_id INT NOT NULL,
    PRIMARY KEY (rating_id, day_id),
    CONSTRAINT fk_rd_rating FOREIGN KEY (rating_id) REFERENCES ratings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rd_day FOREIGN KEY (day_id) REFERENCES game_days(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rating_cache (
    rating_id INT NOT NULL,
    player_id INT NOT NULL,
    games INT NOT NULL DEFAULT 0,
    sum_total DECIMAL(8,2) NOT NULL DEFAULT 0,
    sum_plus DECIMAL(8,2) NOT NULL DEFAULT 0,
    avg_total DECIMAL(8,4) NULL,
    club_score DECIMAL(10,4) NULL,
    pu_count INT NOT NULL DEFAULT 0,
    lh_sum DECIMAL(6,1) NOT NULL DEFAULT 0,
    dop_sum DECIMAL(8,1) NOT NULL DEFAULT 0,
    minus_sum DECIMAL(8,1) NOT NULL DEFAULT 0,
    ci_sum DECIMAL(8,2) NOT NULL DEFAULT 0,
    w_civ INT NOT NULL DEFAULT 0,  g_civ INT NOT NULL DEFAULT 0,
    w_maf INT NOT NULL DEFAULT 0,  g_maf INT NOT NULL DEFAULT 0,
    w_sher INT NOT NULL DEFAULT 0, g_sher INT NOT NULL DEFAULT 0,
    w_don INT NOT NULL DEFAULT 0,  g_don INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rating_id, player_id),
    CONSTRAINT fk_rc_rating FOREIGN KEY (rating_id) REFERENCES ratings(id) ON DELETE CASCADE,
    CONSTRAINT fk_rc_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS albums (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    day_id INT NULL,
    tournament_id INT NULL,
    cover_photo_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_a_day FOREIGN KEY (day_id) REFERENCES game_days(id) ON DELETE SET NULL,
    CONSTRAINT fk_a_tournament FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    album_id INT NOT NULL,
    file VARCHAR(255) NOT NULL,
    thumb VARCHAR(255) NULL,
    sort INT NOT NULL DEFAULT 0,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_p_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body MEDIUMTEXT NULL,
    author_id INT NULL,
    published_at TIMESTAMP NULL,
    pinned TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    details JSON NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(60) PRIMARY KEY,
    v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    nickname VARCHAR(60) NULL,
    success TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ratings (title, is_main) VALUES ('Общий рейтинг', 1);

INSERT INTO settings (k, v) VALUES
    ('min_games_avg', '0'),
    ('rules_text', ''),
    ('about_text', ''),
    ('next_day_hint', '')
