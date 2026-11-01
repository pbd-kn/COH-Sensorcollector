-- Entwurf fuer eine eigene MySQL/MariaDB-Datenbank auf dem Raspi.
-- Noch keine Migration und nicht automatisch importieren.
-- Keine Aenderung an bestehenden Collector-Tabellen.
-- JSON als LONGTEXT: Validierung erfolgt spaeter durch die Anwendung.

CREATE TABLE coh_energy_samples (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sampled_at_utc DATETIME(6) NOT NULL,
    source VARCHAR(64) NOT NULL,
    quality VARCHAR(24) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    KEY sampled_at (sampled_at_utc, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE coh_energy_configuration (
    revision BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at_utc DATETIME(6) NOT NULL,
    configuration_json LONGTEXT NOT NULL,
    PRIMARY KEY (revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE coh_energy_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NOT NULL,
    configuration_revision BIGINT UNSIGNED NOT NULL,
    sample_id BIGINT UNSIGNED NOT NULL,
    device VARCHAR(64) NOT NULL,
    mode VARCHAR(24) NOT NULL,
    status VARCHAR(32) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    requested_json LONGTEXT NOT NULL,
    result_json LONGTEXT NULL,
    PRIMARY KEY (id),
    KEY created_at (created_at_utc, id),
    KEY device_status (device, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
