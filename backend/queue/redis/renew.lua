if not owns(ARGV[1]) then return 0 end
redis.call('ZADD', KEYS[3], now + tonumber(ARGV[2]), ARGV[1])
return 1
