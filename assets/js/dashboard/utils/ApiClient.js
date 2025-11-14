/* global ErrorHandler */

class ApiClient {
  /**
   * Maneja errores de API de forma consistente
   * 
   * @private
   * @static
   * @param {Error|Response} error - El error a manejar
   * @param {string} method - El método HTTP que falló
   * @returns {void}
   */
  static _handleApiError(error, method) {
    // ✅ CORREGIDO: Usar métodos existentes de ErrorHandler
    if (typeof ErrorHandler !== 'undefined' && ErrorHandler) {
      const errorMessage = error instanceof Error 
        ? error.message 
        : (error.statusText || `Error ${error.status || 'desconocido'}`);
      
      // Loggear el error con contexto
      if (typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError(`Error en ${method}: ${errorMessage}`, 'API_CLIENT');
      }
      
      // Mostrar error en UI si es un error de conexión
      if (error instanceof TypeError || (error instanceof Response && error.status === 0)) {
        // Error de red (fetch falló o timeout)
        if (typeof ErrorHandler.showConnectionError === 'function') {
          ErrorHandler.showConnectionError({ status: 0, statusText: errorMessage });
        }
      } else if (error instanceof Response && error.status >= 400) {
        // Error HTTP (4xx, 5xx)
        if (typeof ErrorHandler.showConnectionError === 'function') {
          ErrorHandler.showConnectionError({ 
            status: error.status || 500, 
            statusText: errorMessage 
          });
        }
      } else {
        // Otros errores - mostrar en UI genérico
        if (typeof ErrorHandler.showUIError === 'function') {
          ErrorHandler.showUIError(`Error en API: ${errorMessage}`, 'error');
        }
      }
    } else {
      // Fallback si ErrorHandler no está disponible
      console.error(`[ApiClient] Error en ${method}:`, error);
    }
  }

  static async get(url) {
    try {
      const response = await fetch(url);
      if (!response.ok) {
        // ✅ CORREGIDO: Usar método helper para manejar errores
        this._handleApiError(response, 'GET');
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return await response.json();
    } catch (error) {
      // ✅ CORREGIDO: Usar método helper para manejar errores
      this._handleApiError(error, 'GET');
      throw error; // Re-lanzar para que el llamador pueda manejarlo
    }
  }

  static async post(url, data) {
    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (!response.ok) {
        // ✅ CORREGIDO: Usar método helper para manejar errores
        this._handleApiError(response, 'POST');
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return await response.json();
    } catch (error) {
      // ✅ CORREGIDO: Usar método helper para manejar errores
      this._handleApiError(error, 'POST');
      throw error; // Re-lanzar para que el llamador pueda manejarlo
    }
  }

  static async put(url, data) {
    try {
      const response = await fetch(url, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (!response.ok) {
        // ✅ CORREGIDO: Usar método helper para manejar errores
        this._handleApiError(response, 'PUT');
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return await response.json();
    } catch (error) {
      // ✅ CORREGIDO: Usar método helper para manejar errores
      this._handleApiError(error, 'PUT');
      throw error; // Re-lanzar para que el llamador pueda manejarlo
    }
  }

  static async delete(url) {
    try {
      const response = await fetch(url, { method: 'DELETE' });
      if (!response.ok) {
        // ✅ CORREGIDO: Usar método helper para manejar errores
        this._handleApiError(response, 'DELETE');
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }
      return await response.json();
    } catch (error) {
      // ✅ CORREGIDO: Usar método helper para manejar errores
      this._handleApiError(error, 'DELETE');
      throw error; // Re-lanzar para que el llamador pueda manejarlo
    }
  }
}

export default ApiClient;
