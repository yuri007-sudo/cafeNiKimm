# cafeNiKimm

## Project Overview
A café ordering system with POS and self-ordering features. Includes order tracking via OTP/email.

## Folder Structure
- `backend/` → PHP APIs for menu, orders, and authentication
- `frontend/` → React customer ordering UI
- `database/` → SQL files for database structure and sample data

## Setup Instructions

### Backend
1. Install XAMPP and create a database.
2. Import `database/database_structure.sql` in phpMyAdmin.
3. Configure `backend/config.php` with database credentials.

### Frontend
1. Navigate to `frontend/`
2. Install dependencies: `npm install`
3. Start dev server: `npm run dev`

## Git Workflow
- Branching strategy: use `main` for stable code, create feature branches for new features.
- Pull latest changes before starting work: `git pull origin main`
- Commit regularly with descriptive messages.
