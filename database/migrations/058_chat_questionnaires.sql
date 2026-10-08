-- Chat questionnaires: structured multi-question forms sent to all active registered users.
-- Replies are scoped to the questionnaire (allow_replies controls whether responses are accepted).

CREATE TABLE IF NOT EXISTS chat_questionnaires (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(200) NOT NULL,
    intro            TEXT NULL,
    status           ENUM('draft','scheduled','sending','sent','partial','failed','cancelled') NOT NULL DEFAULT 'draft',
    allow_replies    TINYINT(1) NOT NULL DEFAULT 1,
    scheduled_at     DATETIME NULL,
    sent_at          DATETIME NULL,
    recipients       INT UNSIGNED NOT NULL DEFAULT 0,
    notified_count   INT UNSIGNED NOT NULL DEFAULT 0,
    answered_count   INT UNSIGNED NOT NULL DEFAULT 0,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_chat_questionnaires_status (status, scheduled_at),
    INDEX idx_chat_questionnaires_created (created_at),
    CONSTRAINT fk_chat_q_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_questionnaire_questions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    questionnaire_id BIGINT UNSIGNED NOT NULL,
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    prompt          TEXT NOT NULL,
    qtype           ENUM('text','choice','multichoice','rating','number') NOT NULL DEFAULT 'text',
    options         JSON NULL,
    required        TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_chat_q_questions_qid (questionnaire_id, position),
    CONSTRAINT fk_chat_q_question_q FOREIGN KEY (questionnaire_id) REFERENCES chat_questionnaires(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_questionnaire_answers (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    questionnaire_id BIGINT UNSIGNED NOT NULL,
    question_id     BIGINT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    answer          TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_q_answer_qq_user (question_id, user_id),
    INDEX idx_chat_q_answers_qid_user (questionnaire_id, user_id),
    CONSTRAINT fk_chat_q_answer_q FOREIGN KEY (questionnaire_id) REFERENCES chat_questionnaires(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_q_answer_qq FOREIGN KEY (question_id) REFERENCES chat_questionnaire_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_q_answer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
