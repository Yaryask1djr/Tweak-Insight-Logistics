if not owns(ARGV[1]) then return 0 end
return redis.call('ZREM', KEYS[3], ARGV[1])
