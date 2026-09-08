-- ============================================================
-- Schema: Inventory – publik version
-- Kör detta i phpMyAdmin (eller motsvarande) INNAN du fyller i dina
-- databasuppgifter i inventory_config.php. Alla tabeller är prefixade
-- Inventory_ för att undvika krockar med andra tabeller i samma databas.
-- ============================================================

CREATE TABLE IF NOT EXISTS inventory_Items (
    ID       INT AUTO_INCREMENT PRIMARY KEY,
    Titel    VARCHAR(200) NOT NULL,
    OfBy     VARCHAR(150) NOT NULL,   -- t.ex. artist/författare/tillverkare
    Kind     VARCHAR(50)  NOT NULL,   -- t.ex. CD, Bok, Spel...
    Kategori VARCHAR(100) NOT NULL,   -- t.ex. genre
    Status   VARCHAR(50)  NOT NULL,   -- t.ex. "I hyllan", "Utlånad"
    INDEX idx_inventory_kind (Kind),
    INDEX idx_inventory_kategori (Kategori),
    INDEX idx_inventory_status (Status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Inloggning ----
CREATE TABLE IF NOT EXISTS inventory_Usr (
    ID  INT AUTO_INCREMENT PRIMARY KEY,
    Usr VARCHAR(50) NOT NULL UNIQUE,
    Pwd CHAR(60) NOT NULL   -- bcrypt-hash från PHP:s password_hash(), alltid 60 tecken
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standardanvändare: Admin / 123qwe!!
-- Hashen nedan är genererad med bcrypt (samma algoritm som PHP:s
-- password_hash() med PASSWORD_DEFAULT). Byt lösenord direkt efter
-- installation via admin.php.
INSERT INTO inventory_Usr (Usr, Pwd) VALUES
('Admin', '$2b$10$Hrc5LyNqMrTMYXpxucR9ZeACfCijTmGRVuV4FJXtndO4TicO9.yLG');
