CREATE TABLE fc_auctions(id INTEGER PRIMARY KEY AUTOINCREMENT,item TEXT,minimum INTEGER,bid_step INTEGER,ends_at INTEGER,status TEXT DEFAULT 'open',highest_member INTEGER,highest_bid INTEGER DEFAULT 0,revision INTEGER DEFAULT 1,creator_id INTEGER,created_at INTEGER,closed_at INTEGER,cancel_reason TEXT);
CREATE TABLE fc_bids(id INTEGER PRIMARY KEY AUTOINCREMENT,auction_id INTEGER,member_id INTEGER,amount INTEGER,created_at INTEGER,request_key TEXT UNIQUE);
CREATE TABLE fc_scheduler(id INTEGER PRIMARY KEY,last_success INTEGER);
INSERT INTO fc_scheduler(id) VALUES(1);
