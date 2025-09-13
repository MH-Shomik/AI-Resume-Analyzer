# AI Resume Analyzer Dashboard

A professional dashboard for the AI Resume Analyzer application that provides users with insights into their resume analysis performance, recent activities, and key metrics.

## Features

- **User Statistics**: Track resumes uploaded, jobs saved, analyses performed, and average scores
- **Performance Trends**: Visual chart showing analysis scores over time
- **Recent Activities**: Quick access to recent analyses and uploaded resumes
- **Quick Actions**: Easy navigation to key functions like new analysis and adding jobs
- **Responsive Design**: Works seamlessly on desktop and mobile devices
- **Modern UI**: Dark theme with glass-morphism effects and particle background animation

## Technologies Used

- PHP with MySQL database
- Tailwind CSS for styling
- Chart.js for data visualization
- JavaScript for interactive elements
- Font Awesome icons
- Google Fonts (Inter)

## Installation

1. Ensure you have a web server with PHP and MySQL support
2. Clone or place the project files in your web server directory
3. Import the database schema (if not already included in other files)
4. Configure database connection in `includes/config.php`
5. Set up proper file permissions for uploads if needed

## File Structure
project-root/
├── includes/
│ ├── config.php # Database configuration
│ ├── db.php # Database connection class
│ └── (other includes)
├── templates/
│ └── header.php # Common header template
├── resumes/ # Resume management section
├── jobs/ # Job description management
└── dashboard.php # This dashboard file

## Usage

After logging in, users are presented with their personalized dashboard showing:

1. Key statistics about their resume analysis activity
2. A performance trend chart (if enough data exists)
3. Recent analyses with scores and quick access to results
4. Recent resumes with option to analyze them
5. Quick action buttons for common tasks

## Security Features

- Session-based authentication
- Prepared statements for database queries
- Input sanitization and output escaping
- Protected routes that require authentication

## Browser Support

- Chrome (latest versions)
- Firefox (latest versions)
- Safari (latest versions)
- Edge (latest versions)

## Customization

The dashboard can be customized by:

- Modifying the color scheme in Tailwind classes
- Adjusting the number of items shown in recent lists
- Adding new statistical cards or data visualizations
- Changing the particle background parameters in the JavaScript code

## Support

For support regarding this dashboard, please check the documentation or contact the development team.
