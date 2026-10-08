/** apiGet returns the API envelope, without Axios's outer response. */
export const responseItems = response => {
    const data = response?.data;
    if (Array.isArray(data)) return data;
    if (Array.isArray(data?.deliveries)) return data.deliveries;
    if (Array.isArray(data?.items)) return data.items;
    return [];
};
