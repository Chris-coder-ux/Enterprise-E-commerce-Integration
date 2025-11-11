class ApiClient {
    static async get(url) {
      try {
        const response = await fetch(url);
        if (!response.ok) throw new Error('Network response was not ok');
        return await response.json();
      } catch (error) {
        ErrorHandler.handleError(error);
      }
    }
  
    static async post(url, data) {
      try {
        const response = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });
        if (!response.ok) throw new Error('Network response was not ok');
        return await response.json();
      } catch (error) {
        ErrorHandler.handleError(error);
      }
    }
  
    static async put(url, data) {
      try {
        const response = await fetch(url, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data)
        });
        if (!response.ok) throw new Error('Network response was not ok');
        return await response.json();
      } catch (error) {
        ErrorHandler.handleError(error);
      }
    }
  
    static async delete(url) {
      try {
        const response = await fetch(url, { method: 'DELETE' });
        if (!response.ok) throw new Error('Network response was not ok');
        return await response.json();
      } catch (error) {
        ErrorHandler.handleError(error);
      }
    }
  }
  
  export default ApiClient;
  