# LCD Election Results
## Election Results Management System for Lewis County

A WordPress plugin for managing and displaying election results data, with integration capabilities for GIS mapping.

### Features

- Import election results from CSV files
- Manage candidate information and party affiliations
- Track results by precinct
- Customizable party color schemes
- Database optimization for quick queries
- Integration with LCD County Map plugin
- Comprehensive admin interface
- Automatic party affiliation detection

### Installation

1. Upload the `lcd-election-results` directory to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. The plugin will automatically create necessary database tables
4. Configure party colors and import your first election results

### Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- MySQL 5.6 or higher
- Write permissions for file uploads

### CSV File Format

The plugin accepts CSV files with the following structure:

```csv
Race Name,Candidate Name,Precinct Name,Precinct Number,Vote Count
"County Commissioner","John Doe","North Lewis","101","150"
"County Commissioner","Jane Smith","North Lewis","101","125"
```

File naming convention: `YYYYMMDD.csv` (e.g., `20241105.csv`)

### Directory Structure

```
lcd-election-results/
├── templates/           # Template files for frontend display
├── lcd-election-results.php  # Main plugin file
└── sample csv - Sheet1.csv   # Sample data format
```

### Database Schema

The plugin creates two main tables:

1. `{prefix}_election_candidates`
   - id (Primary Key)
   - candidate_name
   - race_name
   - election_date
   - party
   - created_at
   - updated_at

2. `{prefix}_election_results`
   - id (Primary Key)
   - election_date
   - candidate_id (Foreign Key)
   - precinct_name
   - precinct_number
   - votes
   - filename
   - created_at
   - updated_at

### Administration

The plugin adds three admin menu items:

1. **Election Results**
   - Import CSV files
   - View import history
   - Manage election data

2. **Manage Candidates**
   - View all candidates
   - Edit party affiliations
   - Filter by election date, race, or party
   - Search functionality

3. **Party Colors**
   - Set custom colors for each party
   - Preview color schemes
   - Used in map integration

### Party Affiliation System

The plugin includes an intelligent party affiliation system that:
- Automatically detects party affiliations based on historical data
- Allows manual override through the admin interface
- Maintains consistency across multiple elections
- Special handling for write-in candidates

### Integration with LCD County Map

When used with the LCD County Map plugin, this plugin provides:
- Color-coded precinct visualization
- Detailed election results by precinct
- Interactive data exploration
- Real-time result updates

### Data Import Features

- Validation of CSV format and data
- Duplicate detection and handling
- Error reporting and logging
- Transaction-based imports for data integrity
- Automatic date detection from filename

### Filtering and Search

The candidate management interface includes:
- Date-based filtering
- Race filtering
- Party filtering
- Full-text search for candidates and races
- Sortable columns

### Support

For technical support or feature requests, please contact the LCD development team.

### License

This plugin is licensed under GPL v2 or later. 