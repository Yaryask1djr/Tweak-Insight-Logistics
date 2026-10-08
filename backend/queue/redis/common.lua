-- Single Redis primary only. Check types before writing: errors do not roll back Lua writes.
local expected = {'list', 'zset', 'zset', 'zset'}
for i = 1, 4 do
    local kind = redis.call('TYPE', KEYS[i]).ok
    if kind ~= 'none' and kind ~= expected[i] then
        return redis.error_reply('Queue key has an unexpected type: ' .. KEYS[i])
    end
end
local now = tonumber(redis.call('TIME')[1])

local function decode_job(raw, queue)
    local ok, job = pcall(cjson.decode, raw)
    if not ok or type(job) ~= 'table' or type(job.id) ~= 'string'
        or #job.id < 1 or #job.id > 128 or job.queue ~= queue
        or type(job.job_type) ~= 'string' or #job.job_type < 1 or #job.job_type > 100
        or not string.match(job.job_type, '^[%w_.:%-]+$')
        or type(job.attempts) ~= 'number' or job.attempts < 0 or job.attempts > 100 or job.attempts % 1 ~= 0
        or type(job.max_attempts) ~= 'number' or job.max_attempts < 1
        or job.max_attempts > 100 or job.max_attempts % 1 ~= 0 then
        return nil
    end
    -- Keep payload bytes opaque. cjson would round large integers and change empty arrays.
    local payload_json, original_body
    if type(job.payload_json) == 'string' then
        local valid, payload = pcall(cjson.decode, job.payload_json)
        if not valid or type(payload) ~= 'table' then return nil end
        payload_json = job.payload_json
    elseif type(job.original_body) == 'string' then
        local valid, body = pcall(cjson.decode, job.original_body)
        if not valid or type(body) ~= 'table' or type(body.payload) ~= 'table' then return nil end
        original_body = job.original_body
    elseif type(job.payload) == 'table' then
        original_body = raw -- Preserve legacy ready/delayed jobs without rounding their payloads.
    else
        return nil
    end
    return {
        id = job.id, queue = job.queue, job_type = job.job_type,
        attempts = job.attempts, max_attempts = job.max_attempts,
        created_at = type(job.created_at) == 'number' and job.created_at >= 0
            and job.created_at < 1e12 and job.created_at or now,
        payload_json = payload_json, original_body = original_body,
        error_message = type(job.error_message) == 'string' and job.error_message or nil
    }
end

local function clear_reservation(job)
    job.reservation_token = nil
    job.reserved_at = nil
end

local function owns(raw)
    local expires = redis.call('ZSCORE', KEYS[3], raw)
    return expires and tonumber(expires) > now
end
