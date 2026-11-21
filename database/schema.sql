CREATE TABLE classes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE students (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 class_id INT UNSIGNED NOT NULL,
 name VARCHAR(180) NOT NULL,
 email VARCHAR(254) NOT NULL,
 code_hash VARCHAR(255) DEFAULT NULL,
 code_requested_at DATETIME DEFAULT NULL,
 UNIQUE KEY uq_student_email (email),
 KEY ix_student_class (class_id),
 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE topics (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 class_id INT UNSIGNED NOT NULL,
 number INT UNSIGNED NOT NULL,
 title VARCHAR(500) NOT NULL,
 UNIQUE KEY uq_topic_number (class_id,number),
 UNIQUE KEY uq_topic_class_id (class_id,id),
 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE lessons (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 class_id INT UNSIGNED NOT NULL,
 starts_at DATETIME NOT NULL,
 note VARCHAR(255) NOT NULL DEFAULT '',
 UNIQUE KEY uq_lesson_start (class_id,starts_at),
 UNIQUE KEY uq_lesson_class_id (class_id,id),
 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE selections (
 student_id INT UNSIGNED PRIMARY KEY,
 class_id INT UNSIGNED NOT NULL,
 topic_id INT UNSIGNED DEFAULT NULL,
 lesson_id INT UNSIGNED DEFAULT NULL,
 slot TINYINT UNSIGNED DEFAULT NULL,
 UNIQUE KEY uq_taken_topic (topic_id),
 UNIQUE KEY uq_taken_slot (lesson_id,slot),
 CONSTRAINT ck_slot_pair CHECK ((lesson_id IS NULL AND slot IS NULL) OR (lesson_id IS NOT NULL AND slot BETWEEN 1 AND 8)),
 FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
 FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
 FOREIGN KEY (class_id,topic_id) REFERENCES topics(class_id,id),
 FOREIGN KEY (class_id,lesson_id) REFERENCES lessons(class_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO classes(name) VALUES ('TerciaB'),('2AA'),('3AA');
