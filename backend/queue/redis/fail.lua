local raw = ARGV[1]
if not owns(raw) then return 0 end
local job = cjson.decode(raw)
clear_reservation(job)
job.error_message = ARGV[2]
if job.attempts >= job.max_attempts then
    job.failed_at = now
    redis.call('ZADD', KEYS[4], now, cjson.encode(job))
else
    local delay = math.min(3600, 30 * 4 ^ math.min(job.attempts - 1, 4))
    redis.call('ZADD', KEYS[2], now + delay, cjson.encode(job))
end
redis.call('ZREM', KEYS[3], raw)
return 1
