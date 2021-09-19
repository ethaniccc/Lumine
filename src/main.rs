mod server;
use std::error::Error;

#[tokio::main]
async fn main() -> Result<(), Box<dyn Error>> {
    server::new().await?;
    Ok(())
}
