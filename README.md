# Natak (test build)

A short-drama web app for PHP hosting (PHP 8 with SQLite, no build step).

- `index.html`, `app.js`: the viewer app
- `api.php`: accounts, guest mode, unlocks, likes, saves, shares, view counts, test-mode orders
- `video.php`: streams an episode only to a viewer allowed to watch it
- `admin.php`: admin password, per-show free episodes, video upload, numbers
- Data (database and uploaded videos) is stored outside the web folder in `natak-data/`

Payments and ads are in test mode. No real money moves.

See `ECONOMY.md` for the coin rules and the money maths, and `TESTCASES.md` for the test cases and results.
