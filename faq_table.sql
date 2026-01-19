-- FAQ Table Structure
CREATE TABLE IF NOT EXISTS `FAQ` (
  `FAQ_ID` int(11) NOT NULL AUTO_INCREMENT,
  `Question` varchar(255) NOT NULL,
  `Answer` text NOT NULL,
  `Order_Number` int(11) NOT NULL DEFAULT 0,
  `Created_At` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Updated_At` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`FAQ_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert some sample data
INSERT INTO `FAQ` (`Question`, `Answer`, `Order_Number`) VALUES
('What is FSL?', '<p>FSL (Fantasy Starcraft League) is a competitive league for Starcraft players.</p>', 1),
('How do I join FSL?', '<p>To join FSL, you need to register on our website and submit your application through the registration form.</p>', 2),
('What are the different divisions in FSL?', '<p>FSL has several divisions: Code S (top tier), Code A (middle tier), and Code B (entry tier).</p>', 3); 