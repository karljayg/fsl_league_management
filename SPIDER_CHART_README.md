# FSL Spider Chart Reviewer System

A comprehensive system for peer-reviewed player attribute scoring in the Franchise Star League (FSL), featuring weighted voting, CSV management, and interactive spider chart visualizations.

## Overview

The FSL Spider Chart System allows reviewers to score players across 6 core attributes based on actual match performance. The system uses weighted voting to ensure fair and accurate assessments, with scores normalized to a 0-10 scale for easy comparison.

## Core Features

### 1. Reviewer Management (`manage_reviewers.php`)
- **CSV Import/Export**: Bulk manage reviewers via CSV files
- **Individual Management**: Add, edit, delete, and regenerate unique URLs
- **Voting Statistics**: Track reviewer participation and voting activity
- **Weighted Voting**: Assign different vote weights (default 1.0, admin 2.0)

### 2. URL-Based Voting Interface (`score_match.php`)
- **Secure Access**: Each reviewer gets a unique URL token
- **Match Scoring**: Rate 6 attributes per match (0=Tie, 1=Player1, 2=Player2)
- **VOD Integration**: Direct links to match videos for review
- **Progress Tracking**: Shows which matches have been voted on

### 3. Score Aggregation (`aggregate_scores.php`)
- **Weighted Calculations**: Apply reviewer weights to votes
- **Normalization**: Convert to 0-10 scale for consistency
- **Division-Based**: Separate scores by Code A/B/S divisions
- **Batch Processing**: Handle large datasets efficiently

### 4. Spider Chart Visualization (`spider_chart.php`)
- **Interactive Charts**: Radar/spider charts using Chart.js
- **Player Comparison**: View individual player attributes
- **Division Leaderboards**: Top players by division
- **Responsive Design**: Works on desktop and mobile

### 5. Match Queue Dashboard (`match_queue.php`)
- **Progress Tracking**: Monitor voting completion per match
- **Reviewer Participation**: Track individual reviewer activity
- **Filtering**: Filter by season, code, and voting status
- **Statistics**: Overview of system usage

## Database Schema

### Reviewers Table
```sql
CREATE TABLE Reviewers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    unique_url VARCHAR(255) UNIQUE NOT NULL,
    weight DECIMAL(3,2) DEFAULT 1.00,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Player_Attribute_Votes Table
```sql
CREATE TABLE Player_Attribute_Votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fsl_match_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    attribute ENUM('micro', 'macro', 'clutch', 'creativity', 'aggression', 'strategy'),
    vote INT CHECK (vote IN (0, 1, 2)),
    player1_id INT NOT NULL,
    player2_id INT NOT NULL,
    FOREIGN KEY (fsl_match_id) REFERENCES fsl_matches(fsl_match_id),
    FOREIGN KEY (reviewer_id) REFERENCES Reviewers(id),
    FOREIGN KEY (player1_id) REFERENCES Players(Player_ID),
    FOREIGN KEY (player2_id) REFERENCES Players(Player_ID)
);
```

### Player_Attributes Table
```sql
CREATE TABLE Player_Attributes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_id INT NOT NULL,
    division VARCHAR(10) NOT NULL,
    micro FLOAT,
    macro FLOAT,
    clutch FLOAT,
    creativity FLOAT,
    aggression FLOAT,
    strategy FLOAT,
    FOREIGN KEY (player_id) REFERENCES Players(Player_ID)
);
```

## Attribute Definitions

1. **Micro** - Fine unit control in combat (marine splits, spell usage, etc.)
2. **Macro** - Resource gathering, base expansion, production efficiency
3. **Clutch** - Performance under pressure and pivotal moments
4. **Creativity** - Off-meta builds and unexpected strategies
5. **Aggression** - Proactive attacking style and constant pressure
6. **Strategy** - Build order planning and adaptation

## Usage Workflow

### For Administrators

1. **Setup Reviewers**:
   - Access `manage_reviewers.php`
   - Upload CSV or add reviewers individually
   - Generate unique URLs for each reviewer

2. **Monitor Progress**:
   - Use `match_queue.php` to track voting completion
   - Check reviewer participation statistics
   - Filter matches by various criteria

3. **Generate Scores**:
   - Run `aggregate_scores.php` to calculate weighted scores
   - Review results and adjust weights if needed

4. **View Visualizations**:
   - Access `spider_chart.php` to view player charts
   - Compare players across divisions
   - Share results with the community

### For Reviewers

1. **Access System**:
   - Use unique URL provided by administrator
   - Format: `score_match.php?token=YOUR_UNIQUE_TOKEN`

2. **Score Matches**:
   - Watch match VODs (if available)
   - Rate each attribute (0=Tie, 1=Player1, 2=Player2)
   - Submit votes for all 6 attributes

3. **Track Progress**:
   - See which matches you've already voted on
   - Monitor your voting activity

## CSV Format for Reviewers

```csv
name,unique_url,weight,status
John Doe,abc123def456,1.0,active
Jane Smith,xyz789uvw012,1.0,active
Admin User,admin2024super,2.0,active
Bob Wilson,def456ghi789,1.0,inactive
```

## Security Features

- **Unique URL Tokens**: Each reviewer gets a secure, unique access token
- **Permission-Based Access**: Admin functions require proper permissions
- **Vote Validation**: Prevents duplicate votes and invalid submissions
- **Session Management**: Secure login and logout handling

## Technical Requirements

- **PHP 7.4+**: For modern PHP features and security
- **MySQL 5.7+**: For database functionality
- **Chart.js**: For spider chart visualizations (CDN)
- **Modern Browser**: For responsive design and JavaScript features

## File Structure

```
fsl/
├── manage_reviewers.php      # Reviewer management interface
├── export_reviewers.php      # CSV export functionality
├── score_match.php          # Voting interface for reviewers
├── aggregate_scores.php     # Score calculation script
├── spider_chart.php         # Visualization interface
├── match_queue.php          # Admin dashboard
├── create_reviewers_table.sql # Database setup
└── SPIDER_CHART_README.md   # This documentation
```

## Maintenance

### Regular Tasks

1. **Run Aggregation**: Execute `aggregate_scores.php` after significant voting activity
2. **Monitor Participation**: Check `match_queue.php` for reviewer engagement
3. **Update Reviewers**: Use CSV import/export for bulk reviewer management
4. **Backup Data**: Regular database backups for vote data

### Troubleshooting

- **Permission Issues**: Check user roles and permissions in the database
- **Voting Problems**: Verify reviewer tokens and match data integrity
- **Chart Display**: Ensure Chart.js CDN is accessible
- **Database Errors**: Check foreign key constraints and table relationships

## Future Enhancements

- **Confidence Ratings**: Allow reviewers to rate their confidence in votes
- **Comments System**: Add text comments to justify votes
- **Advanced Analytics**: Statistical analysis of voting patterns
- **API Integration**: REST API for external applications
- **Mobile App**: Native mobile application for voting

## Support

For technical support or questions about the FSL Spider Chart System, contact the system administrator or refer to the database schema documentation. 