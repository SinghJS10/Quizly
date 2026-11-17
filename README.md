# Quizly

A simple online quiz system built with PHP, SQLite, and a responsive CSS UI.

## Run it

```bash
php -S localhost:8000
```

Open http://localhost:8000.

The app creates `quiz.sqlite` automatically and seeds three sample quizzes:

- World Geography
- Science Essentials
- Tech & Digital Life

Demo accounts:

- Player: `user@example.com` / `password`
- Admin: `admin@example.com` / `admin123`

The seeded leaderboard also includes Aarav Mehta, Maya Singh, and Noah Williams with sample quiz attempts. Their demo password is `password`.

Admins can create quizzes from the Admin dashboard. Players can register, take quizzes, and appear on the leaderboard.

Admins can also select which player accounts receive a quiz and set a submission deadline. Assigned players see pending quizzes and due dates on their home page; submissions are blocked after the deadline.
