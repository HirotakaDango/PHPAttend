# Attending App

A lightweight, robust, and mobile-friendly web application for tracking employee attendance. 

## Features
- **GPS-Based Tracking**: Verifies employee location strictly using High-Accuracy GPS hardware.
- **Coordinate Translation**: Automatically translates GPS coordinates into real-world location names (powered by OpenStreetMap).
- **Time/Distance Validation**: Determines status (`Present (On Time)` vs `Absent / Late`) securely using hardware GPS timestamps and Haversine distance calculations.
- **Secure Admin Panel**: Administrative features remain completely hidden and locked down. See instructions below.
- **Smart Loading & Pagination**: Loads historical records seamlessly in blocks of 25 (infinite scroll) to preserve bandwidth.
- **Clear Cache Functionality**: Users can flush browser caches (service workers, local storage, session storage) directly from the loading screen to fix stuck interfaces.

## Requirements
- Web Server (Apache/Nginx/LiteSpeed)
- PHP 7.4 or newer with the following extensions enabled:
  - `pdo_sqlite`
  - `json`
  - `session`
- A secure `HTTPS` connection (Modern browsers block GPS requests on standard `http://`).

## Installation
1. Upload the `index.php` file to your server directory (e.g., `/public_html/attendance/`).
2. Give your web server read/write permissions to the directory (so it can generate the `attending_db.sqlite` database file).
3. Access the file via your browser. 

## How to Access the Admin Panel
The admin account is completely locked down by default. Standard login attempts will silently fail for security purposes.

1. Navigate to the hidden unlock URL:
   `https://yourwebsite.com/index.php?access=admin`
2. The page will immediately redirect back to the standard login screen, but a secure session flag has now been unlocked for your browser.
3. Log in with the following credentials:
   - **Username**: `admin`
   - **Password**: `Admin`

*Note: The admin user is strictly an administrative account and is excluded from all user lists, logs, and attendance statistics.*

## Customizing Configurations
Once logged in as an Admin, click on **Settings** in the bottom navigation bar.
- **Target Latitude/Longitude**: Enter the exact coordinates of your office/target location.
- **Allowed Radius**: Set the maximum physical distance (in meters) a user is permitted to check-in from.
- **Timers**: Configure exactly when "On Time" starts and ends.

## Troubleshooting GPS Failures
If a user receives a "GPS location failed" error:
1. Ensure your website has an SSL certificate (`HTTPS://`).
2. Make sure the user did not deny Location Permissions when prompted.
3. If they are deep indoors, the device's hardware may fail to get a cold GPS satellite fix. Ask them to step near a window or outside.
4. If the interface gets stuck, ask the user to refresh the page and click the "Clear Cache" button on the bootloader screen.