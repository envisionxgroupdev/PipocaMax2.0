-- Pipoca Max - estrutura do banco de dados (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  status ENUM('active','banned') NOT NULL DEFAULT 'active',
  avatar_color VARCHAR(10) NOT NULL DEFAULT '#e50914',
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tmdb_id INT UNSIGNED NULL,
  imdb_id VARCHAR(20) NULL,
  type ENUM('movie','series','anime') NOT NULL DEFAULT 'movie',
  title VARCHAR(255) NOT NULL,
  original_title VARCHAR(255) NULL,
  slug VARCHAR(255) NOT NULL,
  overview TEXT NULL,
  poster VARCHAR(500) NULL,
  backdrop VARCHAR(500) NULL,
  logo VARCHAR(500) NULL,
  year SMALLINT UNSIGNED NULL,
  release_date DATE NULL,
  rating DECIMAL(3,1) NULL,
  runtime SMALLINT UNSIGNED NULL,
  genres VARCHAR(255) NULL,
  age_rating VARCHAR(10) NULL,
  trailer VARCHAR(255) NULL,
  featured TINYINT(1) NOT NULL DEFAULT 0,
  is_release TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('published','draft') NOT NULL DEFAULT 'published',
  views INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_type (type, status),
  KEY idx_tmdb (tmdb_id, type),
  KEY idx_slug (slug),
  KEY idx_c_created (status, created_at),
  KEY idx_c_release (status, is_release, release_date),
  KEY idx_c_views (status, views),
  KEY idx_c_featured (status, featured, updated_at),
  KEY idx_c_typeupd (type, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seasons (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  content_id INT UNSIGNED NOT NULL,
  season_number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(255) NULL,
  overview TEXT NULL,
  poster VARCHAR(500) NULL,
  UNIQUE KEY uq_season (content_id, season_number),
  CONSTRAINT fk_season_content FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS episodes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  season_id INT UNSIGNED NOT NULL,
  content_id INT UNSIGNED NOT NULL,
  episode_number SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(255) NULL,
  overview TEXT NULL,
  still VARCHAR(500) NULL,
  air_date DATE NULL,
  runtime SMALLINT UNSIGNED NULL,
  UNIQUE KEY uq_episode (season_id, episode_number),
  KEY idx_content (content_id),
  CONSTRAINT fk_ep_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
  CONSTRAINT fk_ep_content FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  content_id INT UNSIGNED NULL,
  episode_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  url TEXT NOT NULL,
  kind ENUM('embed','video','hls') NOT NULL DEFAULT 'embed',
  audio VARCHAR(30) NULL,
  quality VARCHAR(20) NULL,
  sort SMALLINT NOT NULL DEFAULT 0,
  KEY idx_content (content_id),
  KEY idx_episode (episode_id),
  CONSTRAINT fk_pl_content FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE,
  CONSTRAINT fk_pl_episode FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  logo VARCHAR(500) NULL,
  category VARCHAR(80) NULL,
  url TEXT NOT NULL,
  kind ENUM('embed','video','hls') NOT NULL DEFAULT 'embed',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort SMALLINT NOT NULL DEFAULT 0,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  source VARCHAR(40) NULL,
  ext_key VARCHAR(64) NULL,
  slug VARCHAR(140) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ch_source (source, ext_key),
  KEY idx_ch_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  user_id INT UNSIGNED NOT NULL,
  content_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, content_id),
  CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fav_content FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  content_id INT UNSIGNED NULL,
  episode_id INT UNSIGNED NULL,
  channel_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  message VARCHAR(500) NOT NULL,
  status ENUM('open','solved') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  ip VARCHAR(45) NOT NULL PRIMARY KEY,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
('site_name', 'Pipoca Max'),
('site_description', 'Filmes, séries, animes e TV online em um só lugar.'),
('maintenance', '0'),
('maintenance_message', 'Estamos fazendo melhorias no Pipoca Max. Voltamos em breve!'),
('allow_register', '1'),
('require_login', '0'),
('tmdb_api_key', ''),
('tmdb_language', 'pt-BR'),
('items_per_page', '30'),
('footer_text', '© Pipoca Max. Todos os direitos reservados.'),
('movie_player_templates', ''),
('episode_player_templates', ''),
('pretty_urls', '1'),
('seo_home_title', ''),
('google_verification', ''),
('google_analytics', ''),
('db_version', '5');
