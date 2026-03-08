-- Run this in pgAdmin or psql
-- First create the database manually in pgAdmin, then run this

CREATE TABLE IF NOT EXISTS users (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(255) UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,
    app_pin    VARCHAR(10) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS books (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    title      VARCHAR(255) NOT NULL DEFAULT 'Untitled Book',
    color      VARCHAR(20) NOT NULL DEFAULT '#d4a84b',
    pin        VARCHAR(10) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS pages (
    id         SERIAL PRIMARY KEY,
    book_id    INTEGER REFERENCES books(id) ON DELETE CASCADE,
    user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    date_label VARCHAR(50) NOT NULL,
    date_iso   VARCHAR(20) NOT NULL,
    content    TEXT DEFAULT '',
    drawing    TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS photos (
    id         SERIAL PRIMARY KEY,
    page_id    INTEGER REFERENCES pages(id) ON DELETE CASCADE,
    user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
    data       TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);
