use std::io::Cursor;
use crate::varint::{WriteProtocolVarIntExt, ReadProtocolVarIntExt};
use std::convert::{TryFrom};

#[test]
fn vari64() -> std::io::Result<()>{
    let buf: Vec<u8> = Vec::new();
    let mut cursor: Cursor<Vec<u8>> = Cursor::new(buf);
    cursor.write_var_u64(1000)?;
    cursor.set_position(0);
    assert_eq!(1000, cursor.read_var_u64()?);
    Ok(())
}

#[test]
fn vari32() -> std::io::Result<()> {
    let buf: Vec<u8> = Vec::new();
    let mut cursor: Cursor<Vec<u8>> = Cursor::new(buf);
    cursor.write_var_i32(-1000)?;
    cursor.set_position(0);
    assert_eq!(-1000, cursor.read_var_i64()?);
    Ok(())
}

#[test]
fn uuid() -> std::io::Result<()> {
    let str = "12345563-1234-1234-1234-121432456642";
    println!("{}, {:?}", str, crate::model::UUID::try_from(str.to_string())?);
    Ok(())
}