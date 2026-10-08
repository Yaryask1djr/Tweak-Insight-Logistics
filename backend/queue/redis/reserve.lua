local queue, token, lease, batch = ARGV[1], ARGV[2], tonumber(ARGV[3]), tonumber(ARGV[4])
local expired = redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', now, 'LIMIT', 0, batch)
for _, raw in ipairs(expired) do
    local job = decode_job(raw, queue)
    if not job then
        redis.call('ZADD', KEYS[4], now, raw)
    else
        clear_reservation(job)
        job.error_message = 'Worker reservation expired.'
        if job.attempts >= job.max_attempts then
            job.failed_at = now
            redis.call('ZADD', KEYS[4], now, cjson.encode(job))
        else
            redis.call('RPUSH', KEYS[1], cjson.encode(job))
        end
    end
    redis.call('ZREM', KEYS[3], raw)
end
local due = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', now, 'LIMIT', 0, batch)
for _, raw in ipairs(due) do
    redis.call('RPUSH', KEYS[1], raw)
    redis.call('ZREM', KEYS[2], raw)
end
for i = 1, batch do
    local raw = redis.call('LINDEX', KEYS[1], 0)
    if not raw then return '' end
    local job = decode_job(raw, queue)
    if not job then
        -- Preserve malformed jobs for inspection instead of silently discarding them.
        redis.call('ZADD', KEYS[4], now, raw)
    elseif job.attempts >= job.max_attempts then
        clear_reservation(job)
        job.failed_at = now
        job.error_message = 'Retry limit exhausted.'
        redis.call('ZADD', KEYS[4], now, cjson.encode(job))
    else
        job.attempts = job.attempts + 1
        job.reservation_token = token
        job.reserved_at = now
        local reserved = cjson.encode(job)
        redis.call('ZADD', KEYS[3], now + lease, reserved)
        redis.call('LPOP', KEYS[1])
        return reserved
    end
    redis.call('LPOP', KEYS[1])
end
return ''
