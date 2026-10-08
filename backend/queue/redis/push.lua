local delay = tonumber(ARGV[2])
if delay > 0 then
    redis.call('ZADD', KEYS[2], now + delay, ARGV[1])
else
    redis.call('RPUSH', KEYS[1], ARGV[1])
end
return 1
